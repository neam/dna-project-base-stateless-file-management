<?php

namespace neam\stateless_file_management\file_storage_components;

use League\Flysystem\FileNotFoundException;
use League\Flysystem\Filesystem;
use League\Flysystem\Adapter\Local;
use Exception;

class LocalFileStorage implements FileStorage
{

    /**
     * @var \propel\models\File
     */
    protected $file;

    static public function create(\propel\models\File $file)
    {
        return new LocalFileStorage($file);
    }

    public function __construct(\propel\models\File $file)
    {
        $this->file =& $file;
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
        /** @var \propel\models\File $this */
        if ($ensure) {
            $this->ensureCorrectLocalFile();
        }
        if (empty($this->file->getPath())) {
            throw new Exception("File's path not set");
        }
        return $this->file->getPath();
    }

    /**
     * @propel
     * @param bool $ensure
     * @return string
     * @throws Exception
     */
    public function getAbsoluteLocalPath($ensure = true)
    {
        /** @var \propel\models\File $this */
        return $this->getLocalBasePath() . $this->getPathForManipulation($ensure);
    }

    public function getExpectedAbsoluteLocalPath()
    {
        /** @var \propel\models\File $this */
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

        // Dummy check
        if (!$this->checkIfCorrectLocalFileIsInPath($correctPath)) {
            if ($this->getPublicFilesS3Filesystem()->has($correctPath)) {
                $metadata = $this->getLocalFilesystem()->getMetadata($correctPath);
            } else {
                $metadata = ["not-in-path"];
            }

            throw new Exception(
                "ensureCorrectLocalFile() failure - local file instance's (id '{$localFileInstance->getId()}') file (id '{$this->getId()}', with expected size {$this->getSize()}) is not in path ('$correctPath') after an attempted move to correct that. Currently in path: "
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

        /** @var \propel\models\File $this */
        $localFileInstance = $this->createLocalFileInstanceIfNecessary();

        // Download the file to where it is expected to be found
        $path = $localFileInstance->getUri();
        if (empty($path)) {
            $path = $this->getCorrectPath();
        }
        if (!$this->checkIfCorrectLocalFileIsInPath($path)) {

            // Interface method for getting the remote binary into a local file stream
            $tmpStream = $this->fetchIntoStream();

            // Remove any existing incorrect file in the location
            try {
                $this->getLocalFilesystem()->delete($path);
            } catch (FileNotFoundException $e) {
            }

            // Save the downloaded file to specified path
            $this->getLocalFilesystem()->writeStream($path, $tmpStream);

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

        /** @var \propel\models\File $this */
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