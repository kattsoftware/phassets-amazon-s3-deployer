<?php

namespace Phassets\Deployers;

use Phassets\Asset;
use Phassets\Interfaces\CacheAdapter;
use Phassets\Interfaces\Configurator;
use Phassets\Interfaces\Deployer;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;

class AmazonS3Deployer implements Deployer
{
    const AWS_S3_URL_SCHEMA = 'http://%s.s3.amazonaws.com/%s';

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
     * then return an absolute URL to its deployed version without performing
     * any further filters' actions.
     *
     * @param Asset $asset
     * @return string|bool An absolute URL to asset already-processed version or false
     *                     if the asset was never deployed using this class.
     */
    public function getDeployedFile(Asset $asset)
    {
        $computedOutput = self::computeOutputBasename($asset);

        $cachedUrl = $this->cacheAdapter->get(self::generateCacheKey($computedOutput));

        if ($cachedUrl !== false) {
            return $cachedUrl;
        }

        if ($this->s3Client->doesObjectExist($this->bucket, $computedOutput)) {
            return sprintf(self::AWS_S3_URL_SCHEMA, $this->bucket, $computedOutput);
        }

        return false;
    }

    /**
     * Given an Asset instance, try to deploy the file using internal
     * rules of this deployer. Returns false in case of failure.
     *
     * @param Asset $asset
     * @return string|bool An absolute URL to asset already-processed version or false
     *                     if the asset wasn't deployed.
     */
    public function deploy(Asset $asset)
    {
        $computedOutput = self::computeOutputBasename($asset);

        try {
            /** @var \Aws\Result $result */
            $result = $this->s3Client->putObject([
                'Bucket' => $this->bucket,
                'Key'    => $computedOutput,
                'ACL'    => 'public-read',
                'Body'   => $asset->getContents(),
            ]);

            $objectUrl = $result->get('ObjectURL');

            $this->cacheAdapter->save(self::generateCacheKey($computedOutput), $objectUrl, self::CACHE_TTL);

        } catch (S3Exception $s3Exception) {
            return false;
        }

        return false;
    }

    /**
     * This must return true/false if the current configuration allows
     * this deployer to deploy processed assets AND it can return previously
     * deployed assets as well.
     *
     * @return bool True if at this time Phassets can use this deployer to
     *              deploy and serve deployed assets, false otherwise.
     */
    public function isSupported()
    {
        $this->awsAccessKey = $this->configurator->getConfig('amazons3_deployer', 'aws_access_key');
        $this->awsSecretKey = $this->configurator->getConfig('amazons3_deployer', 'aws_secret_key');
        $this->bucket = $this->configurator->getConfig('amazons3_deployer', 'bucket');
        $this->bucketRegion = $this->configurator->getConfig('amazons3_deployer', 'bucket_region');

        if (empty($this->awsAccessKey) || empty($this->awsSecretKey) || empty($this->bucket) || empty($this->bucketRegion)) {
            return false;
        }

        $configArray = self::getConfig($this->awsAccessKey, $this->awsSecretKey, $this->bucketRegion);

        try {
            $this->s3Client = new S3Client($configArray);
        } catch (S3Exception $s3Exception) {
            return false;
        }

        return true;
    }

    /**
     * Generates the output full file name of an Asset instance.
     * Pattern: <original_file_name>_<last_modified_timestamp>[.<extension>]
     *
     * @param Asset $asset
     * @return string Generated basename of asset
     */
    private static function computeOutputBasename(Asset $asset)
    {
        $ext = $asset->getExtension() ? '.' . $asset->getExtension() : '';

        return $asset->getFilename() . '_' . $asset->getModifiedTimestamp() . $ext;
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
