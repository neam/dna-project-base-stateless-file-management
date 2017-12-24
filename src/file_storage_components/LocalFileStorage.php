<?php

namespace neam\stateless_file_management\file_storage_components;

use League\Flysystem\FileNotFoundException;
use League\Flysystem\Filesystem;
use League\Flysystem\Adapter\Local;
use Exception;
use propel\models\File;
use propel\models\FileInstance;
use DateTime;

class LocalFileStorage implements FileStorage
{

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
        return new LocalFileStorage($file, $fileInstance);
    }

    public function __construct(File $file, FileInstance $fileInstance = null)
    {
        $this->file =& $file;
        if ($fileInstance !== null) {
            $this->fileInstance =& $fileInstance;
        }
    }

    public function absoluteUrl()
    {
        // Local files are for absolute url purposes assumed to be published to a CDN through some external routine
        return CDN_PATH . 'media/' . $this->file->getPath();
    }

    public function fileContents()
    {
        $file = $this->file;
        $path = $file->ensureCorrectPath();
        if (!$this->getLocalFilesystem()->has($path)) {
            throw new Exception("File contents can not be returned since there is no file at the expected path in the local file system");
        }
        return $this->getLocalFilesystem()->readStream($path);
    }

    public function deliverFileContentsAsHttpResponse()
    {
        // TODO: Set output headers based on mimetype etc
        $stream = $this->fileContents();
        $BUFSIZ = 4095;
        while (!feof($stream)) {
            fread($stream, $BUFSIZ);
        }
        fclose($stream);
        exit();
    }

    protected $localFilesystem;

    /**
     * @propel
     * @return Filesystem
     */
    protected function getLocalFilesystem()
    {
        if (empty($this->localFilesystem)) {
            $this->localFilesystem = new Filesystem(new Local($this->getLocalBasePath()));
        }
        return $this->localFilesystem;
    }

    /**
     * @propel
     * @yii
     * @return string
     */
    public function getLocalBasePath()
    {
        return rtrim(LOCAL_TMP_FILES_PATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    /**
     * @propel
     * @param bool $ensure
     * @return string
     * @throws Exception
     */
    public function getPathForManipulation($ensure = true)
    {
        $file = $this->file;
        if ($ensure) {
            $this->ensureCorrectLocalFile();
        }
        if (empty($file->getPath())) {
            throw new Exception("File's path not set");
        }
        return $file->getPath();
    }

    /**
     * @propel
     * @param bool $ensure
     * @return string
     * @throws Exception
     */
    public function getAbsoluteLocalPath($ensure = true)
    {
        return $this->getLocalBasePath() . $this->getPathForManipulation($ensure);
    }

    public function getExpectedAbsoluteLocalPath()
    {
        return $this->getLocalBasePath() . $this->getPathForManipulation($ensure = false);
    }

    public function ensuredLocallyGuessedMimetype()
    {

        // Actually downloads the file
        $inputFilePath = $this->getAbsoluteLocalPath();

        // Detects the mime type primarily by file contents
        $mimeType = \MimeType::guessMimeType($inputFilePath);

        // TODO: Store guess in order to prevent repeated downloads of the file only for mimetype-guessing

        return $mimeType;

    }

    /**
     * @param null $localPath
     * @throws Exception
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function determineFileMetadata($localPath = null)
    {
        $file = $this->file;

        if ($localPath === null) {
            $localFileInstance = $this->getEnsuredLocalFileInstance();
            $localPath = $localFileInstance->getUri();
        }

        if (empty($file->getMimetype())) {
            $file->setMimetype($this->getLocalFilesystem()->getMimetype($localPath));
        }
        if ($file->getSize() === null) {
            $file->setSize($this->getLocalFilesystem()->getSize($localPath));
        }
        if (empty($file->getFilename())) {
            $absoluteLocalPath = $this->getLocalBasePath() . $localPath;
            $filename = pathinfo($absoluteLocalPath, PATHINFO_FILENAME);
            $file->setFilename($filename);
        }
        // TODO: hash/checksum
        // $md5 = md5_file($absoluteLocalPath);
        // Possible TODO: image width/height if image
        // getimagesize($absoluteLocalPath)

    }

    public function putContents($fileContents)
    {
        $file = $this->file;

        $path = $file->ensureCorrectPath();
        if ($this->getLocalFilesystem()->has($path)) {
            $this->getLocalFilesystem()->delete($path);
        }
        $this->getLocalFilesystem()->write($path, $fileContents);
        $file->setMimetype(null);
        $file->setSize(null);
        $this->determineFileMetadata($path);
        if (!$this->checkIfCorrectLocalFileIsInPath($path)) {
            throw new Exception("Put file contents failed");
        }
        // Store local file instance
        $localFileInstance = $this->createLocalFileInstanceIfNecessary();
        $localFileInstance->setUri($path);
        $localFileInstance->save();
    }

    /**
     * Ensures:
     * 1. That the file-record have a local file instance
     * 2. That the local file instance actually has it's file in place locally
     * @propel
     * @param null $params
     */
    public function ensureCorrectLocalFile()
    {
        \Operations::status(__METHOD__);

        $file = $this->file;

        // Get the ensured local file instance with a binary copy of the file (binary copy is guaranteed to be found at this file instance's uri but not necessarily in the correct path)
        $localFileInstance = $this->getEnsuredLocalFileInstance();

        // Move the local file instance to correct path if not already there
        $correctPath = $file->getCorrectPath();
        $this->moveTheLocalFileInstanceToPathIfNotAlreadyThere($localFileInstance, $correctPath);

        // Dummy check (catching issues during development rather than anything that is likely to happen when things are up and running)
        if (!$this->checkIfCorrectLocalFileIsInPath($correctPath)) {
            if ($this->getLocalFilesystem()->has($correctPath)) {
                $metadata = $this->getLocalFilesystem()->getMetadata($correctPath);
            } else {
                $metadata = ["not-in-path"];
            }

            if (isset($metadata["timestamp"])) {
                $metadata["timestamp_YmdHis"] = DateTime::createFromFormat("U", $metadata["timestamp"])->format("Y-m-d H:i:s");
            }

            throw new Exception(
                "ensureCorrectLocalFile() failure - local file instance's (id '{$localFileInstance->getId()}', storage component ref '{$localFileInstance->getStorageComponentRef()}') file (id '{$file->getId()}', with expected size {$file->getSize()}) is not in path ('$correctPath') after an attempted move to correct that. Currently in path: "
                . print_r($metadata, true)
            );
        }

        // Set the correct path in file.path
        if ($file->getPath() !== $correctPath) {
            $file->setPath($correctPath);
        }

        // Save the file and file instance only first now when we know it is in place
        $localFileInstance->save();
        $file->save();

    }

    protected function createLocalFileInstanceIfNecessary()
    {
        $file = $this->file;
        $localFileInstance = $file->localFileInstance();

        // Create a local file instance since none exists - but do not save it until we have put the binary in place...
        if (empty($localFileInstance)) {
            $correctPath = $file->getCorrectPath();
            $localFileInstance = new \propel\models\FileInstance();
            $localFileInstance->setStorageComponentRef('local');
            $file->setFileInstanceRelatedByLocalFileInstanceId($localFileInstance);
        }

        return $localFileInstance;

    }

    /**
     * @propel
     * @return mixed|null|\propel\models\FileInstance
     * @throws Exception
     * @throws \Propel\Runtime\Exception\PropelException
     */
    protected function getEnsuredLocalFileInstance()
    {
        \Operations::status(__METHOD__);

        $file = $this->file;
        $localFileInstance = $this->createLocalFileInstanceIfNecessary();

        // Download the file to where it is expected to be found
        $path = $localFileInstance->getUri();
        if (empty($path)) {
            $path = $file->getCorrectPath();
        }
        if (!$this->checkIfCorrectLocalFileIsInPath($path)) {

            $fileStorage = $file->firstAvailableFileStorage();

            if ($fileStorage instanceof LocalFileStorage) {
                throw new Exception("The first available file storage can't be local file storage when we are ensuring local files");
            }

            \Operations::status("First available file storage: " . get_class($fileStorage));

            // Interface method for getting the remote binary into a local file stream
            $fileContents = $fileStorage->fileContents();

            // Remove any existing incorrect file in the location
            try {
                $this->getLocalFilesystem()->delete($path);
            } catch (FileNotFoundException $e) {
            }

            // Save the downloaded file to specified path
            if (is_resource($fileContents)) {
                $this->getLocalFilesystem()->writeStream($path, $fileContents);
            } else {
                $this->getLocalFilesystem()->write($path, $fileContents);
            }

        }

        // Update file instance to reflect the path to where it is currently found
        $localFileInstance->setUri($path);

        return $localFileInstance;

    }

    /**
     * @param \propel\models\FileInstance $fileInstance
     * @param $path
     */
    protected function moveTheLocalFileInstanceToPathIfNotAlreadyThere(
        \propel\models\FileInstance $fileInstance,
        $path
    )
    {
        \Operations::status(__METHOD__);

        if (empty($path)) {
            throw new Exception("Supplied path to move file instance with id '{$fileInstance->getId()}' to is empty");
        }

        if (empty($fileInstance->getUri())) {
            throw new Exception("File instance with id '{$fileInstance->getId()}' has an empty uri/path");
        }

        $file = $this->file;
        if ($fileInstance->getUri() !== $path) {
            if (!$this->checkIfCorrectRemotePublicFileIsInPath($path)) {
                // Remove any existing incorrect file in the location
                try {
                    $this->getLocalFilesystem()->delete($path);
                } catch (FileNotFoundException $e) {
                }
                $this->getLocalFilesystem()->rename($fileInstance->getUri(), $path);
                $fileInstance->setUri($path);
            }
        }

    }

    /**
     * @propel
     * @return bool
     * @throws Exception
     */
    protected function checkIfCorrectLocalFileIsInPath($path)
    {
        \Operations::status(__METHOD__);
        \Operations::status($path);

        // Check if file exists
        $exists = $this->getLocalFilesystem()->has($path);
        if (!$exists) {
            //\Operations::status("Does not exist");
            return false;
        }

        $file = $this->file;

        if ($file->getSize() === null) {
            throw new Exception(
                "A file already exists in the path ('{$path}') but we can't compare it to the expected file size since it is missing from the file record ('{$this->getId()}') metadata"
            );
        }

        // Check if existing file has the correct size
        $size = $this->getLocalFilesystem()->getSize($path);
        if ($size !== $file->getSize()) {
            //\Operations::status("Wrong size (expected: {$this->getSize()}, actual: $size)");
            return false;
        }

        // Check hash/contents to verify that the file is the same
        // TODO

        //\Operations::status("Correct remote public file is in path");
        return true;

    }

}