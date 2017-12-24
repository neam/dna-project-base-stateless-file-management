<?php

namespace neam\stateless_file_management\file_storage_components;

use AppJson;
use GuzzleHttp;
use propel\models\File;
use propel\models\FileInstance;
use Exception;

class FilestackFileStorage implements FileStorage
{

    use FilestackSecuredFileStorageTrait;
    use FilestackConvertibleFileStorageTrait;

    /**
     * @var \propel\models\File
     */
    protected $file;

    /**
     * @var \propel\models\FileInstance
     */
    protected $fileInstance;

    static public function create(File $file, FileInstance $fileInstance)
    {
        return new FilestackFileStorage($file, $fileInstance);
    }

    public function __construct(File $file, FileInstance $fileInstance)
    {
        $this->file =& $file;
        $this->fileInstance =& $fileInstance;
    }

    public function absoluteUrl()
    {
        return static::filestackCdnUrl(static::signFilestackUrl($this->fileInstance->getUri()));
    }

    public function fileContents()
    {
        $targetFileHandle = tmpfile();
        if (!is_resource($targetFileHandle)) {
            throw new Exception("Could not create a temporary file");
        }
        $url = $this->absoluteUrl();
        return $this->file->downloadRemoteFileToStream($url, $targetFileHandle);
    }

    static public function filestackCdnUrl($filestackUrl)
    {

        // Use Filestack's CDN - Including legacy Filepicker's CDN since it still is in use by Filestack for probably buggy reasons
        $cdnUrl = str_replace(
            ["www.filestackapi.com", "www.filepicker.io"],
            ["cdn.filestackcontent.com", "cdn.filepicker.io"],
            $filestackUrl
        );

        // Optionally use custom filestack domain (if configured)
        if (defined('FILESTACK_CUSTOM_DOMAIN') && !empty(FILESTACK_CUSTOM_DOMAIN)) {
            $cdnUrl = str_replace(
                ["cdn.filestackcontent.com", "cdn.filepicker.io"],
                ["cdn." . FILESTACK_CUSTOM_DOMAIN, "cdn." . FILESTACK_CUSTOM_DOMAIN],
                $cdnUrl
            );
        }

        return $cdnUrl;

    }

    static public function createFileInstanceWithMetadataByFilestackUrl($filestackUrl)
    {

        $fileInstance = new FileInstance();
        $fileInstance->setStorageComponentRef('filestack');
        $fileInstance->setUri($filestackUrl);
        static::decorateFileInstanceWithFilestackMetadataByFilestackUrl($fileInstance, $filestackUrl);
        return $fileInstance;

    }

    static public function decorateFileInstanceWithFilestackMetadataByFilestackUrl(
        \propel\models\FileInstance $fileInstance,
        $filestackUrl
    )
    {

        $handle = static::extractHandleFromFilestackUrl($filestackUrl);
        $client = new GuzzleHttp\Client();
        $filestackMetadataUrl = 'https://www.filestackapi.com/api/file/' . $handle . '/metadata';
        $requestUrl = File::signFilestackUrl($filestackMetadataUrl);
        $response = $client->get($requestUrl);

        $data = new \stdClass();
        $data->fpfile = AppJson::decode($response->getBody());
        $data->fpkey = FILESTACK_API_KEY;

        $fileInstance->setDataJson(json_encode($data));

    }

    static public function extractHandleFromFilestackUrl($filestackUrl)
    {

        $urlinfo = parse_url($filestackUrl);
        $_ = explode("/", $urlinfo["path"]);
        $return = null;
        if ($_[1] === "api" && $_[2] === "file") {
            $return = $_[3];
        } elseif (count($_) === 2) {
            $return = $_[1];
        }
        if (empty($return)) {
            throw new \Exception(
                "Empty handle extracted from filestack url ('$filestackUrl') - (\$_: " . print_r($_, true) . ")"
            );
        }
        return $return;

    }

    static public function createFileFromFilestackUrl($filestackUrl)
    {

        $fileInstance = static::createFileInstanceWithMetadataByFilestackUrl($filestackUrl);

        $file = new File();
        static::setFileMetadataFromFilestackFileInstanceMetadata($file, $fileInstance);
        $file->setFileInstanceRelatedByFilestackFileInstanceId($fileInstance);

        return $file;

    }

    static public function setFileMetadataFromFilestackFileInstanceMetadata(
        \propel\models\File &$file,
        \propel\models\FileInstance $fileInstance
    )
    {

        $data = AppJson::decode($fileInstance->getDataJson());

        $file->setSize($data->fpfile->size);
        $file->setMimetype($data->fpfile->mimetype);
        $file->setFilename($data->fpfile->filename);
        $file->setOriginalFilename($data->fpfile->filename);

    }

}
