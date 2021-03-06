<?php

namespace App\Command\Search;

use App\Command\CommandHelperTrait;
use App\Command\GameData\SaintCoinachRedisCommand;
use App\Common\Service\Redis\Redis;
use App\Common\Utils\Arrays;
use App\Common\Utils\Language;
use App\Service\Search\SearchContent;
use App\Common\Service\ElasticSearch\ElasticSearch;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class UpdateSearchCommand extends Command
{
    use CommandHelperTrait;

    protected function configure()
    {
        $this
            ->setName('UpdateSearchCommand')
            ->setDescription('Deploy all search data to live!')
            ->addOption('environment', null, InputOption::VALUE_OPTIONAL, 'prod OR dev', 'prod')
            ->addOption('full', null, InputOption::VALUE_OPTIONAL, 'Perform a full import, regardless of existing entries', false)
            ->addOption('content', null, InputOption::VALUE_OPTIONAL, 'Run a specific content', null)
            ->addOption('id', null, InputOption::VALUE_OPTIONAL, 'Run a specific content id', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this
            ->setSymfonyStyle($input, $output)
            ->title('SEARCH')
            ->startClock();

        $envAllowed  = in_array($input->getOption('environment'), ['prod', 'staging']);
        $environment = $envAllowed ? 'ELASTIC_SERVER_PROD' : 'ELASTIC_SERVER_LOCAL';
        $isFullRun   = $this->input->getOption('full') == 1;

        if ($input->getOption('environment') == 'prod') {
            $this->io->success('DEPLOYING TO PRODUCTION');
        }

        $elastic = new ElasticSearch($environment);

        // import documents to ElasticSearch
        try {
            foreach (SearchContent::LIST as $contentName) {
                if ($input->getOption('content') &&
                    $input->getOption('content') != $contentName) {
                    continue;
                }

                $index = strtolower($contentName);
                $ids   = (array)Redis::Cache()->get("ids_{$contentName}");
                $idsEs = (array)Redis::cache()->get("ids_{$contentName}_es");

                if (empty($ids)) {
                    $this->io->error('No IDs for content: ' . $contentName);
                    continue;
                }

                $total = count($ids);
                $docs  = [];

                $this->io->text("<info>ElasticSearch import: {$total} {$contentName} documents to index: {$index}</info>");

                if ($isFullRun) {
                    // delete index for a clean slate
                    $elastic->deleteIndex($index);

                    // create index
                    $elastic->addIndexGameData($index);
                }

                // temporarily -1 the refresh interval for this index
                $elastic->putSettings([
                    "index" => "$index",
                    "body" => [
                        "settings" => [
                            "refresh_interval" => "-1"
                        ]
                    ]
                ]);

                // Add documents to elastic
                $count = 0;
                $this->io->progressStart($total);
                foreach ($ids as $id) {
                    $count++;

                    if ($input->getOption('id') &&
                        $input->getOption('id') != $id) {
                        continue;
                    }

                    // if this is not a full run and the id is already in the array, skip!
                    if ($isFullRun === false && in_array($id, $idsEs) === true) {
                        $this->io->progressAdvance($count);
                        $count = 0;
                        continue;
                    }


                    // grab content
                    $content = Redis::Cache()->get("xiv_{$contentName}_{$id}");

                    // if no name_en, skip it!
                    if (empty($content->Name_en)) {
                        continue;
                    }

                    // remove arrays from content
                    foreach ($content as $field => $value) {
                        if (is_array($value) && $field != "Recipes") {
                            unset($content->{$field});
                        }
                    }

                    // convert the whole thing to an array
                    $content = json_decode(json_encode($content), true);

                    // ensure content types are correctly assigned
                    $content = Arrays::ensureStrictDataTypes($content);

                    // handle custom string columns
                    $content = $this->handleCustomStringColumns($contentName, $content);

                    // handle clean up
                    $content = $this->handleCleanUp($contentName, $content);

                    // append to docs
                    $docs[$id] = $content;

                    // un comment to debug insert issues
                    // $elastic->addDocument($index, 'search', $id, $content);

                    // insert docs
                    if ($count >= ElasticSearch::MAX_BULK_DOCUMENTS) {
                        $this->io->progressAdvance($count);
                        $elastic->bulkDocuments($index, 'search', $docs);
                        $docs  = [];
                        $count = 0;
                    }
                }

                // add any reminders
                if (count($docs) > 0) {
                    $elastic->bulkDocuments($index, 'search', $docs);
                }

                $this->io->progressFinish();

                $elastic->putSettings([
                    "index" => "$index",
                    "body" => [
                        "settings" => [
                            "refresh_interval" => "1s"
                        ]
                    ]
                ]);

                // save new id list
                Redis::Cache()->set("ids_{$contentName}_es", $idsEs, SaintCoinachRedisCommand::REDIS_DURATION);
            }
        } catch (\Exception $ex) {
            print_r($ex->getMessage());
            throw $ex;
        }

        unset($content, $docs);
        $this->complete()->endClock();
    }

    private function handleCleanUp(string $contentName, array $content)
    {
        if ($contentName === 'Quest') {
            //
            // Remove junk
            //
            foreach (range(0, 170) as $num) {
                unset(
                    $content["TextData_en"],
                    $content["TextData_de"],
                    $content["TextData_fr"],
                    $content["TextData_ja"],
                    $content["TextData_kr"],
                    $content["TextData_cn"],

                    $content["Level{$num}"],
                    $content["Level{$num}Target"],
                    $content["Level{$num}TargetID"],
                    $content["ScriptInstruction{$num}_en"],
                    $content["ScriptInstruction{$num}_de"],
                    $content["ScriptInstruction{$num}_fr"],
                    $content["ScriptInstruction{$num}_ja"],
                    $content["ScriptArg{$num}"],

                    $content["PreviousQuest0"]["Level{$num}"],
                    $content["PreviousQuest0"]["Level{$num}Target"],
                    $content["PreviousQuest0"]["Level{$num}TargetID"],
                    $content["PreviousQuest0"]["ScriptInstruction{$num}_en"],
                    $content["PreviousQuest0"]["ScriptInstruction{$num}_de"],
                    $content["PreviousQuest0"]["ScriptInstruction{$num}_fr"],
                    $content["PreviousQuest0"]["ScriptInstruction{$num}_ja"],
                    $content["PreviousQuest0"]["ScriptArg{$num}"]
                );
            }
        }

        return $content;
    }

    /**
     * This will create 2 new columns:
     * - NameCombined_[Lang]: Combines the fields of content where 2 names may
     *                        be present (eg Titles have Name + NameFemale)
     * - NameLocale: Provides a column with all names from all languages so
     *               1 column can be searched via multiple languages
     */
    private function handleCustomStringColumns(string $contentName, array $content)
    {
        //
        // Copy balloon dialogue to a name field, just for simplicity
        //
        if ($contentName == 'Balloon') {
            foreach (Language::LANGUAGES as $lang) {
                $content["Name_{$lang}"] = $content["Dialogue_{$lang}"] ?? '';
            }
        }

        //
        // Build NameCombined fields
        //
        foreach (Language::LANGUAGES as $lang) {
            $content["NameCombined_{$lang}"] = $content["Name_{$lang}"] ?? '';

            // append on female names
            if ($contentName == 'Title') {
                $content["NameCombined_{$lang}"] .= " " . ($content["NameFemale_{$lang}"] ?? '');
            }

            $content["NameCombined_{$lang}"] = trim($content["NameCombined_{$lang}"]);
        }

        //
        // Build NameLocale fields
        //
        $content['NameLocale'] = '';
        foreach (Language::LANGUAGES as $lang) {
            $content['NameLocale'] .= ' ' . ($content["NameCombined_{$lang}"] ?? '');
        }

        $content['NameLocale'] = trim($content['NameLocale']);

        return $content;
    }
}
