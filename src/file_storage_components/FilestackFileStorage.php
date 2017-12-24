<?php

namespace neam\stateless_file_management\file_storage_components;

use AppJson;
use Filestack\Filelink;
use GuzzleHttp;
use propel\models\File;
use propel\models\FileInstance;
use Exception;
use Filestack\FilestackClient;
use Filestack\FilestackSecurity;
use Filestack\FilestackException;

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

    static public function create(File $file, FileInstance $fileInstance = null)
    {
        return new FilestackFileStorage($file, $fileInstance);
    }

    public function __construct(File $file, FileInstance $fileInstance = null)
    {
        $this->file =& $file;
        if ($fileInstance === null) {
            $fileInstance = $file->getFileInstanceRelatedByPublicFilesS3FileInstanceId();
        }
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

    public function deliverFileContentsAsHttpResponse()
    {
        $url = $this->absoluteUrl();
        header("Location: $url");
        exit();
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

    /**
     * @var FilestackClient
     */
    protected $filestackClient;

    /**
     * @return FilestackClient
     */
    public function getFilestackClient()
    {

        if (empty($this->filestackClient)) {

            $security = new FilestackSecurity(FILESTACK_API_SECRET);
            $this->filestackClient = new FilestackClient(FILESTACK_API_KEY, $security);

        }
        return $this->filestackClient;

    }

    /**
     * Ensures:
     * 1. That the file-record have a remote public file instance
     * 2. That the remote public file instance actually has it's file in place
     * @propel
     * @param null $params
     */
    public function ensureFilestackFileInstance()
    {
        \Operations::status(__METHOD__);

        $file = $this->file;

        // Get the ensured remote public file instance with a binary copy of the file (binary copy is guaranteed to be found at this file instance's uri but not necessarily in the correct path)
        $filestackFileInstance = $this->getEnsuredFilestackFileInstance();


        /*

        // Move the remote public file instance to correct path if not already there
        $correctPath = $file->getCorrectPath();
        $this->moveTheFilestackFileInstanceToPathIfNotAlreadyThere($filestackFileInstance, $correctPath);

        // Dummy check
        if (!$this->checkIfCorrectFilestackFileIsInPath($correctPath)) {
            if ($this->getFilestackClient()->has($correctPath)) {
                $metadata = $this->getFilestackClient()->getMetadata($correctPath);
            } else {
                $metadata = ["not-in-path"];
            }

            throw new Exception(
                "ensureCorrectLocalFile() failure - remote public file instance's (id '{$filestackFileInstance->getId()}') file (id '{$this->getId()}', with expected size {$this->getSize()}) is not in path ('$correctPath') after an attempted move to correct that. Currently in path: "
                . print_r($metadata, true)
            );
        }

        // Set the correct path in file.path
        if ($file->getPath() !== $correctPath) {
            $file->setPath($correctPath);
        }

        */

        // Save the file and file instance only first now when we know it is in place
        $filestackFileInstance->save();
        $file->save();

    }

    /**
     * @propel
     * @return mixed|null|\propel\models\FileInstance
     * @throws Exception
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function getEnsuredFilestackFileInstance()
    {
        \Operations::status(__METHOD__);

        $file = $this->file;
        $filestackClient = $this->getFilestackClient();

        // Create a filestack file instance since none exists - but do not save it until we have put the binary in place...
        $filestackFileInstance = $file->getFileInstanceRelatedByFilestackFileInstanceId();
        if (empty($filestackFileInstance)) {
            $filestackFileInstance = new \propel\models\FileInstance();
            $filestackFileInstance->setStorageComponentRef('filestack');
            $file->setFileInstanceRelatedByFilestackFileInstanceId($filestackFileInstance);
        }

        // Ensure the correct filestack file contents is uploaded
        $filestackUrl = $filestackFileInstance->getUri();
        if (empty($filestackUrl) || !$this->checkIfCorrectFilestackFileIsAtFilestackUrl($filestackUrl)) {

            $localFile = LocalFileStorage::create($file);

            // TODO: Ability to prevent the following method from attempting to download from the filestack instance (in case the wrong file is currently uploaded to the existing filestack url)
            $localFileInstance = $localFile->getEnsuredLocalFileInstance();
            if (empty($localFileInstance)) {
                throw new Exception("No local file instance available to upload the file from");
            }

            // Upload/overwrite the file
            $filepath = $localFile->getAbsoluteLocalPath();


            if (empty($filestackUrl)) {
                $options = [];
                $options['mimetype'] = $file->getMimetype();
                /** @var Filelink $filelink */
                $filelink = $filestackClient->upload($filepath);
            } else {
                $handle = static::extractHandleFromFilestackUrl($filestackUrl);
                /** @var Filelink $filelink */
                $filelink = $filestackClient->overwrite($filepath, $handle);
            }

            $filestackUrl = $filelink->url();

            // Update file instance to reflect the path to where it is currently found
            $filestackFileInstance->setUri($filestackUrl);

        }

        return $filestackFileInstance;

    }

    /**
     * @param \propel\models\FileInstance $fileInstance
     * @param $path
     */
    protected function moveTheFilestackFileInstanceToPathIfNotAlreadyThere(
        \propel\models\FileInstance $fileInstance,
        $path
    )
    {
        \Operations::status(__METHOD__);

        $file = $this->file;
        if ($fileInstance->getUri() !== $path) {
            if (!$this->checkIfCorrectFilestackFileIsInPath($path)) {
                // Remove any existing incorrect file in the location
                try {
                    $this->getFilestackClient()->delete($path);
                } catch (FileNotFoundException $e) {
                }
                $this->getFilestackClient()->rename($fileInstance->getUri(), $path);
                $fileInstance->setUri($path);
            }
        }

    }

    /**
     * @propel
     * @return bool
     * @throws Exception
     */
    protected function checkIfCorrectFilestackFileIsAtFilestackUrl($filestackUrl)
    {
        \Operations::status(__METHOD__);
        \Operations::status($filestackUrl);

        try {

            // Check if remote file exists
            $metadata = $this->getFilestackClient()->getMetaData($filestackUrl);

            $file = $this->file;

            if ($file->getSize() === null) {
                throw new Exception(
                    "A file already exists in the remote filestack url ('{$filestackUrl}') but we can't compare it to the expected file size since it is missing from the file record ('{$file->getId()}') metadata"
                );
            }

            // Check if existing file has the correct size
            $size = $metadata["size"];
            if ($size !== $file->getSize()) {
                //\Operations::status("Wrong size (expected: {$this->getSize()}, actual: $size)");
                return false;
            }

            // Check hash/contents to verify that the file is the same
            // TODO

            //\Operations::status("Correct remote public file is in path");
            return true;

        } catch (FilestackException $e) {
            if ($e->getMessage() === "File not found") {
                $exists = false;
            } else {
                throw $e;
            }
        }

    }

}
