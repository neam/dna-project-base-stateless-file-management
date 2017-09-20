<?php

namespace neam\stateless_file_management;

use GuzzleHttp;
use propel\models\File;
use propel\models\FileInstance;
use \ContextIoHelper;

trait ContextIoEmailAttachmentFileTrait
{

    /**
     * The URL returned is a public URL that doesn't require authentication or OAuth signatures.
     * You can redirect users to it to trigger a download from their browser.
     * That way, you don't have to download the data to your app server only to stream it back to your user.
     * The URL is valid for a 2 minute period after the time it has been generated. There is no limit to the number of downloads until the URL expires.
     * Docs: https://context.io/docs/2.0/accounts/files/content
     *
     * @param FileInstance $fileInstance
     * @return mixed
     */
    static public function contextIoEmailAttachmentPublicUrl(\propel\models\FileInstance $fileInstance)
    {
        $data = json_decode($fileInstance->getDataJson());
        $contextIoFileId = $data->contextIoAttachmentObject->file_id;
        $contextIoApiKey = $data->contextIoApiKey;
        $contextIoAccountId = $data->contextIoAccountId;
        ContextIoHelper::contextIOAllowedAccountId($contextIoAccountId);
        $contextIO = ContextIoHelper::contextIO();
        $response = $contextIO->getFileURL($contextIoAccountId, $contextIoFileId);
        if (!$response) {
            throw new ContextIoEmailAttachmentPublicUrlException('Fetching of the context.io attachment resource url failed');
        }
        $publicUrl = $response->getRawResponse();
        return $publicUrl;
    }

    static public function restApiContextIoEmailAttachmentPublicUrlForwardingEndpoint(\propel\models\File $file)
    {
        return "//" . APPVHOST . "/api/v0/file/{$file->getId()}?retrieveEmailAttachment=1";
    }

    static protected function createFileInstanceWithMetadataFromContextIoAttachmentMetadata($contextIoAttachmentMetadata
    ) {

        $fileInstance = new FileInstance();
        $fileInstance->setStorageComponentRef('context-io');
        static::decorateFileInstanceWithContextIoMetadataFromContextIoAttachmentMetadata(
            $fileInstance,
            $contextIoAttachmentMetadata
        );
        $fileInstance->setUri($contextIoAttachmentMetadata->contextIoAttachmentObject->resource_url);
        return $fileInstance;

    }

    static protected function decorateFileInstanceWithContextIoMetadataFromContextIoAttachmentMetadata(
        \propel\models\FileInstance $fileInstance,
        $contextIoAttachmentMetadata
    ) {

        $fileInstance->setDataJson(json_encode($contextIoAttachmentMetadata));

    }

    static public function createFileFromContextIoAttachmentMetadata($contextIoAttachmentMetadata)
    {

        $fileInstance = static::createFileInstanceWithMetadataFromContextIoAttachmentMetadata(
            $contextIoAttachmentMetadata
        );

        $file = new File();
        static::setFileMetadataFromContextIoEmailAttachmentFileInstanceMetadata($file, $fileInstance);
        $file->setFileInstanceRelatedByContextIoFileInstanceId($fileInstance);

        return $file;

    }

    static protected function setFileMetadataFromContextIoEmailAttachmentFileInstanceMetadata(
        \propel\models\File &$file,
        \propel\models\FileInstance $fileInstance
    ) {

        $contextIoAttachmentMetadata = json_decode($fileInstance->getDataJson());

        $file->setSize($contextIoAttachmentMetadata->contextIoAttachmentObject->size);
        $file->setMimetype($contextIoAttachmentMetadata->contextIoAttachmentObject->type);
        $file->setFilename($contextIoAttachmentMetadata->contextIoAttachmentObject->file_name);
        $file->setOriginalFilename($contextIoAttachmentMetadata->contextIoAttachmentObject->file_name);

    }

}

class ContextIoEmailAttachmentPublicUrlException extends \Exception
{
}
