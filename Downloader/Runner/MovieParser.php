<?php declare(strict_types=1);

namespace Downloader\Runner;

use Co\Channel;
use Co\WaitGroup;
use Downloader\Runner\Decrypt\DecryptionInterface;
use ProgressBar\Manager;
use ProgressBar\Registry;
use Psr\Container\ContainerInterface;
use Swoole\Coroutine;
use Symfony\Component\Console\Output\OutputInterface;

abstract class MovieParser
{

    protected const DOWNLOAD_FILE_MAX = 1024;

    /**
     * 每组M3u8文件
     * @var $m3u8Files array
     */
    protected $m3u8Files = [];

    /**
     * 每组ts队列
     * @var array
     */
    protected $m3u8Urls;

    /**
     * @var $container ContainerInterface
     */
    protected $container;

    /**
     * 解密接口列表
     * @var DecryptionInterface $decryptInterface
     */
    protected $decryptInterface;

    /**
     * @var $movieParserClass
     */
    protected $movieParserClass;

    /**
     * Downloader 配置文件
     * @var array $config
     */
    protected $config = [];

    /**
     * @var OutputInterface $outputConsole
     */
    protected $outputConsole;
    protected $inputConsole;

    /**
     * @param $m3u8Url string 视频文件信息
     * @param $movieTs  string ts文件名称
     * @return string  返回完整ts视频地址
     */
    abstract protected function parsedTsUrl(string $m3u8Url, string $movieTs): string;

    public function runParser(): ?array
    {
        $wg = new WaitGroup();

        $progressBar = $this->container->get('bar');
        /**
         * @var $registry Registry
         */
        $registry = $progressBar->getRegistry();
        $registry->setValue('max', count($this->m3u8Urls));
        $progressBar->setRegistry($registry);

        foreach ($this->m3u8Urls as $index => $m3u8Url) {
            $wg->add();
            Coroutine::create(function (WaitGroup $wg, $m3u8Url, Manager $progressBar, $index) {
                defer(function () use ($wg, $progressBar) {
                    $wg->done();
                });

                /**
                 * @var $client HttpClient
                 */
                $client = $this->container->get('client');

                try {
                    $client->get()->request(trim($m3u8Url));
                } catch (RetryRequestException $e) {
                    $this->container->get('log')->record($e);
                } finally {
                    $client->closed();
                }

                if ($client->getBodySize() > self::DOWNLOAD_FILE_MAX) {
                    $m3u8File = new M3u8File();
                    $this->m3u8Files[$index] = $m3u8File;

                    // make queue
                    [$splQueue, $data, $tsBindMap] = array(
                        new \SplQueue(), $client->getBody(), []
                    );

                    preg_match_all("#,\s(.*?)\.ts#is", $data, $matches);
                    $splArray = new \SplFixedArray(count($matches[1]));

                    foreach ($matches[1] as $id => $ts) {
                        // 完整ts地址
                        $fromTsFile = trim($ts) . '.ts';
                        $tsUrl = $this->parsedTsUrl($m3u8Url, $fromTsFile);
                        $splQueue->add($id, $tsUrl);
                        $basename = basename($tsUrl);
                        $tsBindMap[$basename] = $tsUrl;
                        $splArray[$id] = $basename;
                    }

                    if ($keyInfo = $this->getDecryptionParameters($data)) {
                        if (isset($keyInfo['vi'])) {
                            $m3u8File->setDecryptIV($keyInfo['vi']);
                        }

                        $m3u8File->setDecryptKey($keyInfo['key']);
                        $m3u8File->setDecryptMethod($keyInfo['method']);

                        $decryptInstance = $this->decryptInterface[$this->movieParserClass] ?? null;

                        if (!$decryptInstance) {
                            throw new \RuntimeException(
                                "The {$this->movieParserClass} class does not implement the decryptionInterface."
                            );
                        }
                        $m3u8File->setDecryptInstance($decryptInstance);
                    }

                    // 队列
                    $m3u8File->setSplQueue($splQueue);
                    $m3u8File->setGroupId($this->movieParserClass);
                    $m3u8File->setOutput($this->getOutputDir());
                    $m3u8File->setM3u8Id($index);
                    $m3u8File->setTsCount($splQueue->count());
                    $m3u8File->setChannel(new Channel($this->getConcurrentNumber()));
                    $m3u8File->setMergedTsArray($splArray);
                    $m3u8File->setConcurrent($this->getConcurrentNumber());
                    $m3u8File->setBindTsMap($tsBindMap);

                    $progressBar->advance();
                } else {
                    // 地址错误走这里
                    // todo ....
//                    var_dump($m3u8Url);
                }
            }, $wg, $m3u8Url, $progressBar, $index);
        }
        try {
            if ($wg->wait(60) === false) {
                throw new \RuntimeException(
                    'Channel timeout, failed file information::' . var_export($this->m3u8Urls, true)
                );
            }
        } catch (\RuntimeException $e) {
            $this->container->get('log')->record($e);
        }
        return $this->m3u8Files;
    }

    private function getConcurrentNumber()
    {
        $concurrent = intval($this->config['concurrent'] ?? '15');

        return $concurrent < 1 ? 15 : $concurrent;
    }

    protected function getOutputDir()
    {
        $outputDir = $this->config['output'];
        if (substr($outputDir, -1) === DIRECTORY_SEPARATOR) {
            return $outputDir . $this->getGroupName() . DIRECTORY_SEPARATOR;
        }

        return $outputDir . DIRECTORY_SEPARATOR . $this->getGroupName() . DIRECTORY_SEPARATOR;
    }

    protected function getGroupName()
    {
        return substr(md5($this->movieParserClass), 32 - 9);
    }

    /**
     * 获取加密KEY
     * @param string $data
     * @return array|null
     */
    protected function getDecryptionParameters(string $data): ?array
    {

        $keyInfo = $this->getParsekey($data);
        if ($keyInfo) {
            /**
             * @var $client HttpClient
             */
            $client = $this->container->get('client');

            try {
                $client->get()->request($keyInfo['keyUri']);
            } catch (RetryRequestException $e) {
                throw $e;
            } finally {
                $client->closed();
            }

            if ($client->isSucceed()) {
                $keyInfo['key'] = $client->getBody();
                return $keyInfo;
            }
        }

        return [];
    }

    protected function getParsekey($data)
    {
        $doesIt = preg_match("#\#EXT-X-KEY:METHOD=(.*?)\#EXTINF#is", $data, $matches);

        if ($doesIt) {
            $line = trim($matches[1]);
            $result = explode(',', $line);
            $method = $result[0];
            preg_match('/URI="(.*?)"/is', $result[1], $keyUri);
            $keyUri = $keyUri[1];

            switch (count($result)) {
                case 2:
                    return compact('method', 'keyUri');
                case 3:
                    $vi = $result[2];
                    return compact('method', 'keyUri', 'vi');
                default:
                    break;
            }
        }
        return [];
    }

    public function setMovieParserClass($movieParserClass)
    {
        $this->movieParserClass = $movieParserClass;
        return $this;
    }

    /**
     * @param array $m3u8Urls
     * @return MovieParser
     */
    public function setM3u8s(array $m3u8Urls): MovieParser
    {
        $this->m3u8Urls = $m3u8Urls;
        return $this;
    }

    public function setDecryptionInterface($decryptInterface)
    {
        $this->decryptInterface = $decryptInterface;
        return $this;
    }

    /**
     * @param $outputConsole
     * @return $this
     */
    public function setOutputConsole($outputConsole)
    {
        $this->outputConsole = $outputConsole;
        return $this;
    }

    public function setInputConsole($inputConsole)
    {
        $this->inputConsole = $inputConsole;
        return $this;
    }

    public function setConfig($config)
    {
        $this->config = $config;
        return $this;
    }

    public function setContainer(ContainerInterface $container): MovieParser
    {
        $this->container = $container;
        return $this;
    }
}