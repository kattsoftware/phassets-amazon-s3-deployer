<?php

namespace Phassets\Deployers;

use Phassets\Exceptions\PhassetsInternalException;
use Phassets\Asset;
use Phassets\Interfaces\CacheAdapter;
use Phassets\Interfaces\Configurator;
use Phassets\Interfaces\Deployer;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;

class AmazonS3Deployer implements Deployer
{
    const AWS_S3_URL_SCHEMA = 'https://%s.s3.amazonaws.com/%s';
    const ACL_PUBLIC_READ = 'public-read';

    const CACHE_TTL = 3600;

    /**
     * @var Configurator
     */
    private $configurator;

    /**
     * @var CacheAdapter
     */
    private $cacheAdapter;

    /**
     * @var string AWS Access Key (see AWS Console)
     */
    private $awsAccessKey;

    /**
     * @var string AWS Secret Key (see AWS Console)
     */
    private $awsSecretKey;

    /**
     * @var string Bucket name
     */
    private $bucket;

    /**
     * @var string Bucket region identity (e.g. us-east-1)
     */
    private $bucketRegion;

    /**
     * @var S3Client AWS S3 Client from SDK package
     */
    private $s3Client;

    /**
     * @var bool Whether to set the ContentType parameter when uploading the asset to AWS
     */
    private $autodetectMime;

    /**
     * @var string What should trigger re-deployment of an asset ('filemtime' (default), 'md5', 'sha1')
     */
    private $trigger;

    /**
     * Deployer constructor.
     *
     * @param Configurator $configurator Chosen and loaded Phassets configurator.
     * @param CacheAdapter $cacheAdapter Chosen and loaded Phassets cache adapter (if any)
     */
    public function __construct(Configurator $configurator, CacheAdapter $cacheAdapter)
    {
        $this->configurator = $configurator;
        $this->cacheAdapter = $cacheAdapter;
    }

    /**
     * Attempt to retrieve a previously deployed asset; if it does exist,
     * then update the Asset instance's outputUrl property, without performing
     * any further filters' actions.
     *
     * @param Asset $asset
     * @return bool Whether the Asset was previously deployed or not;
     *              If yes, then Asset's outputUrl property will be updated.
     */
    public function isPreviouslyDeployed(Asset $asset)
    {
        $computedOutput = $this->computeOutputBasename($asset);
        $cacheKey = self::generateCacheKey($computedOutput);

        $cachedUrl = $this->cacheAdapter->get($cacheKey);

        if ($cachedUrl !== false) {
            $asset->setOutputUrl($cachedUrl);

            return true;
        }

        if ($this->s3Client->doesObjectExist($this->bucket, $computedOutput)) {
            $objectUrl = sprintf(self::AWS_S3_URL_SCHEMA, $this->bucket, $computedOutput);
            $asset->setOutputUrl($objectUrl);
            $this->cacheAdapter->save($cacheKey, $objectUrl, self::CACHE_TTL);


            return true;
        }

        return false;
    }

    /**
     * Given an Asset instance, try to deploy is using internal
     * rules of this deployer and update Asset's property outputUrl.
     *
     * @param Asset $asset Asset instance whose outputUrl property will be modified
     * @throws PhassetsInternalException If the deployment process fails
     */
    public function deploy(Asset $asset)
    {
        $computedOutput = $this->computeOutputBasename($asset);

        try {
            $putObjectParams = [
                'Bucket' => $this->bucket,
                'Key'    => $computedOutput,
                'ACL'    => self::ACL_PUBLIC_READ,
                'Body'   => $asset->getContents(),
            ];

            if ($this->autodetectMime) {
                $mime = null;

                if (function_exists('\\GuzzleHttp\\Psr7\\mimetype_from_extension')) {
                    $mime = \GuzzleHttp\Psr7\mimetype_from_extension($asset->getExtension());
                }

                if ($mime === null && function_exists('mime_content_type')) {
                    $mime = mime_content_type($asset->getFullPath());
                }

                if ($mime !== null) {
                    $putObjectParams['ContentType'] = $mime;
                }
            }

            /** @var \Aws\Result $result */
            $result = $this->s3Client->putObject($putObjectParams);

            $objectUrl = $result->get('ObjectURL');

            $this->cacheAdapter->save(self::generateCacheKey($computedOutput), $objectUrl, self::CACHE_TTL);

            $asset->setOutputUrl($objectUrl);
        } catch (S3Exception $s3Exception) {
            throw new PhassetsInternalException(
                'AmazonS3Deployer: S3Exception encountered',
                $s3Exception->getCode(),
                $s3Exception
            );
        }
    }

    /**
     * This must throw a PhassetsInternalException if the current configuration
     * doesn't allow this deployer to deploy processed assets.
     *
     * @throws PhassetsInternalException If at this time Phassets can't use this deployer to
     *                                   deploy and serve deployed assets
     */
    public function isSupported()
    {
        $this->awsAccessKey = $this->configurator->getConfig('amazons3_deployer', 'aws_access_key');
        $this->awsSecretKey = $this->configurator->getConfig('amazons3_deployer', 'aws_secret_key');
        $this->bucket = $this->configurator->getConfig('amazons3_deployer', 'bucket');
        $this->bucketRegion = $this->configurator->getConfig('amazons3_deployer', 'bucket_region');
        $this->autodetectMime = $this->configurator->getConfig('amazons3_deployer', 'autodetect_mime');
        $this->trigger = $this->configurator->getConfig('amazons3_deployer', 'changes_trigger');

        if (empty($this->awsAccessKey)) {
            throw new PhassetsInternalException('AmazonS3Deployer: No or invalid "aws_access_key" setting.');
        }

        if (empty($this->awsSecretKey)) {
            throw new PhassetsInternalException('AmazonS3Deployer: No or invalid "aws_secret_key" setting.');
        }

        if (empty($this->bucket)) {
            throw new PhassetsInternalException('AmazonS3Deployer: No or invalid "bucket" setting.');
        }

        if (empty($this->bucketRegion)) {
            throw new PhassetsInternalException('AmazonS3Deployer: No or invalid "bucket_region" setting.');
        }

        $configArray = self::getConfig($this->awsAccessKey, $this->awsSecretKey, $this->bucketRegion);

        try {
            $this->s3Client = new S3Client($configArray);
        } catch (S3Exception $s3Exception) {
            throw new PhassetsInternalException(
                'AmazonS3Deployer: S3Exception encountered.',
                $s3Exception->getCode(),
                $s3Exception
            );
        }
    }

    /**
     * Generates the output full file name of an Asset instance.
     * Pattern: <original_file_name>_<last_modified_timestamp>[.<extension>]
     *
     * @param Asset $asset
     * @return string Generated basename of asset
     */
    private function computeOutputBasename(Asset $asset)
    {
        $ext = $asset->getExtension() ? '.' . $asset->getExtension() : '';

        switch ($this->trigger) {
            case 'md5':
                $suffix = $asset->getMd5();
                break;
            case 'sha1':
                $suffix = $asset->getSha1();
                break;
            case 'filemtime':
            default:
                $suffix = $asset->getModifiedTimestamp();
        }

        return $asset->getFilename() . '_' . $suffix . $ext;
    }

    /**
     * Returns config param required for S3Client initialization, by providing
     * the necessary settings.
     *
     * @param string $accessKey AWS Access Key credential
     * @param string $secretKey AWS Secret Key credential
     * @param string $bucketRegion Region name for chosen bucket
     * @see http://docs.aws.amazon.com/general/latest/gr/rande.html#s3_region (region column)
     *
     * @return array Config param for initialization of Aws\S3\S3Client
     */
    private static function getConfig($accessKey, $secretKey, $bucketRegion)
    {
        return [
            'credentials' => [
                'key'    => $accessKey,
                'secret' => $secretKey
            ],
            'debug'       => false,
            'region'      => $bucketRegion,
            'version'     => 'latest'
        ];
    }

    /**
     * CacheAdapter specific cache key.
     *
     * @param string $computedFileName
     * @return string
     */
    private static function generateCacheKey($computedFileName)
    {
        return 'ph_awss3_' . $computedFileName;
    }
}
