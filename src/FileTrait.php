<?php

namespace neam\stateless_file_management;

use Exception;
use Suggestions;

/**
 * Helper trait that encapsulates DNA project base file-handling logic
 *
 * Some principles:
 *  §1 Local file manipulation should be available simply by reading LOCAL_TMP_FILES_PATH . DIRECTORY_SEPARATOR . $file->getPath() as defined in getLocalAbsolutePath()
 *  §2 The path to the file is relative to the storage component's file system and should follow the format $file->getId() . DIRECTORY_SEPARATOR . $file->getFilename() - this is the file's "correct path" and ensures that multiple files with the same filename can be written to all file systems
 *  §3 Running $file->ensureCorrectLocalFile() ensures §1 and §2 (designed to run before local file manipulation, post file creation/modification time and/or as a scheduled process)
 *  §4 File instance records tell us where binary copies of the file are stored
 *  §5 File instances should (if possible) store it's binary copy using the relative path provided by $file->getPath(), so that retrieval of the file's binary contents is straightforward and eventual public url's follow the official path/name supplied by $file->getPath()
 *
 * Current storage components handled by this trait:
 *  - local (implies that the binary is stored locally)
 *  - filestack (implies that the binary is stored at filestack)
 *  - filestack-pending (implies that the binary is pending an asynchronous task to finish, after which point the instance will be converted into a 'filestack' instance)
 *  - filepicker (legacy filestack name, included only to serve filepicker-stored files until all have been converted to filestack-resources)
 *  - public-files-s3 (implies that the binary is stored in Amazon S3 in a publicly accessible bucket)
 *
 * Class FileTrait
 */
trait FileTrait
{

    use LocalFileTrait;
    use FilestackFileTrait;
    use FilestackSecuredFileTrait;
    use FilestackConvertibleFileTrait;
    use PublicFilesS3FileTrait;
    use ContextIoFileTrait;

    /**
     * @propel
     * @yii
     * @param $url
     * @param $targetFileHandle
     * @return mixed
     * @throws Exception
     */
    static public function downloadRemoteFileToStream($url, $targetFileHandle)
    {
        Suggestions::status(__METHOD__);
        if (empty($url)) {
            throw new Exception("Invalid url argument ('$url') to downloadRemoteFileToStream()");
        }
        if (substr($url, 0, 2) === "//") {
            $url = "http:" . $url;
        }
        $BUFSIZ = 4095;
        $rfile = fopen($url, 'r');
        if (!$rfile) {
            throw new Exception("Failed to open file handle against $url");
        }
        $lfile = $targetFileHandle;
        while (!feof($rfile)) {
            fwrite($lfile, fread($rfile, $BUFSIZ), $BUFSIZ);
        }
        fclose($rfile);
        Suggestions::status("Downloaded file from $url");
        return $lfile;
    }

    /**
     * @propel
     * @return string
     * @throws Exception
     */
    public function getCorrectPath()
    {
        /** @var \propel\models\File $this */
        $id = $this->getId();
        if (empty($id)) {
            throw new Exception("File's id not set - can't calculate the correct path");
        }
        $filename = \neam\Sanitize::filename($this->getFilename());
        return $this->getId() . DIRECTORY_SEPARATOR . $filename;
    }

    public function ensureCorrectPath()
    {
        if (empty($this->getId())) {
            $this->save();
        }
        if ($this->getPath() !== $this->getCorrectPath()) {
            $this->setPath($this->getCorrectPath());
        }
        return $this->getPath();
    }

    /**
     * @propel
     * @return mixed|null|\propel\models\FileInstance
     */
    public function localFileInstance()
    {
        /** @var \propel\models\File $this */
        return $this->getFileInstanceRelatedByLocalFileInstanceId(
        ) ? $this->getFileInstanceRelatedByLocalFileInstanceId() : null;
    }

    /**
     * Should return first best remote file instance where it is expected to find
     * a binary copy of the file when there is no local file available
     * @propel
     * @return mixed|null|\propel\models\FileInstance
     */
    public function remoteFileInstance()
    {
        /** @var \propel\models\File $this */
        if ($fileInstance = $this->getFileInstanceRelatedByFilestackFileInstanceId()) {
            return $fileInstance;
        }
        if ($fileInstance = $this->getFileInstanceRelatedByContextIoFileInstanceId()) {
            return $fileInstance;
        }
        if ($fileInstance = $this->getFileInstanceRelatedByPublicFilesS3FileInstanceId()) {
            return $fileInstance;
        }
        return null;
    }

    /**
     * Should return the first best remote public file instance where it is expected to find
     * a public binary copy of the file
     * @propel
     * @return mixed|null|\propel\models\FileInstance
     */
    public function remotePublicFileInstance()
    {
        /** @var \propel\models\File $this */
        if ($fileInstance = $this->getFileInstanceRelatedByPublicFilesS3FileInstanceId()) {
            return $fileInstance;
        }
        return null;
    }

    /**
     * @propel
     * @throws Exception
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function determineFileMetadata($localPath = null)
    {
        /** @var \propel\models\File $this */

        if ($localPath === null) {
            $localFileInstance = $this->getEnsuredLocalFileInstance();
            $localPath = $localFileInstance->getUri();
        }

        if (empty($this->getMimetype())) {
            $this->setMimetype($this->getLocalFilesystem()->getMimetype($localPath));
        }
        if ($this->getSize() === null) {
            $this->setSize($this->getLocalFilesystem()->getSize($localPath));
        }
        if (empty($this->getFilename())) {
            $absoluteLocalPath = $this->getLocalBasePath() . $localPath;
            $filename = pathinfo($absoluteLocalPath, PATHINFO_FILENAME);
            $this->setFilename($filename);
        }
        // TODO: hash/checksum
        // $md5 = md5_file($absoluteLocalPath);
        // Possible TODO: image width/height if image
        // getimagesize($absoluteLocalPath)

    }

    /**
     * Return the first available absolute url to an instance of the current file
     * @propel
     * @yii
     * @return string
     */
    public function absoluteUrl()
    {
        if (get_class($this) === 'File') {
            return $this->absoluteUrl_yii($this);
        } else {
            return $this->absoluteUrl_propel($this);
        }
    }

    /**
     * @yii
     * @param \File $file
     * @return mixed|null|string
     */
    protected function absoluteUrl_yii(\File $file)
    {
        if (($fileInstance = $file->publicFilesS3FileInstance) && !empty($fileInstance->uri)) {
            return static::publicFilesS3Url($fileInstance->uri);
        }
        if (($fileInstance = $file->filestackFileInstance) && !empty($fileInstance->uri)) {
            return static::filestackCdnUrl(static::signFilestackUrl($fileInstance->uri));
        }
        if (($fileInstance = $file->contextIoFileInstance) && !empty($fileInstance->uri)) {
            throw new Exception("Absolute url for Yii apps not supported");
        }
        if (($fileInstance = $file->localFileInstance) && !empty($fileInstance->uri)) {
            // Local files are assumed published to a CDN
            return CDN_PATH . 'media/' . $file->path;
        }
        return null;
    }

    /**
     * @propel
     * @param \propel\models\File $file
     * @return mixed|null|string
     */
    protected function absoluteUrl_propel(\propel\models\File $file)
    {
        if (($fileInstance = $file->getFileInstanceRelatedByPublicFilesS3FileInstanceId(
            )) && !empty($fileInstance->getUri())
        ) {
            return $file->fileInstanceAbsoluteUrl($fileInstance);
        }
        if (($fileInstance = $file->getFileInstanceRelatedByFilestackFileInstanceId()) && !empty($fileInstance->getUri(
            ))
        ) {
            return $file->fileInstanceAbsoluteUrl($fileInstance);
        }
        if (($fileInstance = $file->getFileInstanceRelatedByContextIoFileInstanceId()) && !empty($fileInstance->getUri())) {
            return $file->fileInstanceAbsoluteUrl($fileInstance);
        }
        if (($fileInstance = $file->getFileInstanceRelatedByLocalFileInstanceId()) && !empty($fileInstance->getUri())) {
            return $file->fileInstanceAbsoluteUrl($fileInstance);
        }
        return null;
    }

    /**
     * Return pending file instance (one that will be available at a later point in time)
     * @propel
     * @return string ''
     */
    public function pendingFileInstance()
    {
        /** @var \propel\models\File $this */
        return $this->getFileInstanceRelatedByFilestackPendingFileInstanceId(
        ) ? $this->getFileInstanceRelatedByFilestackPendingFileInstanceId() : null;
    }

    /**
     * @propel
     * @param \propel\models\FileInstance $fileInstance
     * @return mixed|string
     * @throws Exception
     */
    public function fileInstanceAbsoluteUrl(\propel\models\FileInstance $fileInstance, $immediateDownload = false)
    {

        $storageComponentRef = $fileInstance->getStorageComponentRef();
        /** @var \propel\models\File $this */
        switch ($storageComponentRef) {
            case 'public-files-s3':
                return static::publicFilesS3Url($fileInstance->getUri());
            case 'local':
                // Local files are assumed published to a CDN
                return CDN_PATH . 'media/' . $this->getPath();
            case 'filepicker':
            case 'filestack':
                return static::filestackCdnUrl(static::signFilestackUrl($fileInstance->getUri()));
            case 'context-io':
                if ($immediateDownload) {
                    return static::contextIoPublicUrl($fileInstance);
                }
                return static::restApiContextIoPublicUrlForwardingEndpoint($this);
        }
        throw new Exception(
            "fileInstanceAbsoluteUrl() encountered an unsupported storage component ref ('$storageComponentRef')"
        );

    }

}
