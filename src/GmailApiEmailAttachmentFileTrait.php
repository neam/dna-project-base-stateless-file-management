<?php

namespace neam\stateless_file_management;

use neam\Sanitize;
use propel\models\File;
use propel\models\FileInstance;
use AppJson;
use GmailApiMessageObjectParser;

trait GmailApiEmailAttachmentFileTrait
{

    public function ensureLocalGmailApiAttachmentData(\propel\models\FileInstance $fileInstance)
    {
        $fileInstanceDataJson = AppJson::decode($fileInstance->getDataJson());
        $gmailApiInboxEmail = $fileInstanceDataJson->gmailApiInboxEmail;
        $attachmentMetadata = (array) $fileInstanceDataJson->gmailApiParsedAttachmentMetadata;

        $appGoogleApiClient = \AppGoogleApiClient::createClientWithAccessToGmailApiInboxEmail($gmailApiInboxEmail);
        $attachmentsMetadata = [$attachmentMetadata];
        $appGoogleApiClient->fetchMissingAttachmentBodyData($attachmentsMetadata);
        $attachmentMetadata = $attachmentsMetadata[0];

        return $attachmentMetadata;
    }

    public function streamGmailApiAttachmentData($attachmentMetadata)
    {

        $attachmentData = GmailApiMessageObjectParser::base64UrlDecode(
            $attachmentMetadata["bodyBase64UrlEncoded"]
        );

        foreach ([
                     "content-type",
                     "content-id",
                 ] as $relevantHeader) {
            if (isset($attachmentMetadata['headers']->{$relevantHeader})) {
                header("$relevantHeader: " . $attachmentMetadata['headers']->{$relevantHeader});
            }
        }
        header("content-transfer-encoding: base64");
        header("content-transfer-encoding: attachment; filename=" . Sanitize::filename($this->getFilename()));

        echo $attachmentData;
        exit();
    }

    public function fetchAndStreamRemoteGmailApiAttachmentData(\propel\models\FileInstance $fileInstance)
    {
        $attachmentMetadata = $this->ensureLocalGmailApiAttachmentData($fileInstance);
        $this->streamGmailApiAttachmentData($attachmentMetadata);
    }

    /**
     * Fetch the data (if attachment data needs to be fetched), stream it and save it (overwriting previously saved data if any)
     * @param FileInstance $fileInstance
     * @throws \Exception
     */
    public function fetchStreamAndSaveRemoteGmailApiAttachmentData(\propel\models\FileInstance $fileInstance)
    {
        $attachmentMetadata = $this->ensureLocalGmailApiAttachmentData($fileInstance);
        $attachmentData = GmailApiMessageObjectParser::base64UrlDecode(
            $attachmentMetadata["bodyBase64UrlEncoded"]
        );
        $this->putContents($attachmentData);
        $this->streamGmailApiAttachmentData($attachmentMetadata);
    }

    static public function restApiGmailApiRemoteAttachmentDataStreamingEndpoint(\propel\models\File $file)
    {
        return "//" . APPVHOST . "/api/v0/file/{$file->getId()}?retrieveMailAttachment=1";
    }

    static protected function createFileInstanceWithMetadataFromGmailApiAttachmentMetadata($gmailApiAttachmentMetadata
    ) {

        $fileInstance = new FileInstance();
        $fileInstance->setStorageComponentRef('gmail-api');
        static::decorateFileInstanceWithGmailApiMetadataFromGmailApiAttachmentMetadata(
            $fileInstance,
            $gmailApiAttachmentMetadata
        );

        $attachmentMetadata = $gmailApiAttachmentMetadata->gmailApiParsedAttachmentMetadata;
        if (!empty($attachmentMetadata["bodyRemoteResourceMetadata"])) {
            $fileInstance->setUri("remote-attachment-metadata");
        }

        return $fileInstance;

    }

    static protected function decorateFileInstanceWithGmailApiMetadataFromGmailApiAttachmentMetadata(
        \propel\models\FileInstance $fileInstance,
        $gmailApiAttachmentMetadata
    ) {

        $fileInstance->setDataJson(json_encode($gmailApiAttachmentMetadata));

    }

    static public function createFileFromGmailApiAttachmentMetadata($gmailApiAttachmentMetadata)
    {

        $file = new File();

        $attachmentMetadata = $gmailApiAttachmentMetadata->gmailApiParsedAttachmentMetadata;

        // only create a gmail api file instance if we have remote resource metadata
        if (!empty($attachmentMetadata["bodyRemoteResourceMetadata"])) {
            $fileInstance = static::createFileInstanceWithMetadataFromGmailApiAttachmentMetadata(
                $gmailApiAttachmentMetadata
            );
            static::setFileMetadataFromGmailApiEmailAttachmentFileInstanceMetadata($file, $fileInstance);
            $file->setFileInstanceRelatedByGmailApiFileInstanceId($fileInstance);
        }

        // if we already have attachment data at creation time - create a local file instance as well
        if (!empty($attachmentMetadata["bodyBase64UrlEncoded"])) {
            $data = GmailApiMessageObjectParser::base64UrlDecode($attachmentMetadata["bodyBase64UrlEncoded"]);
            $file->putContents($data);
        }

        return $file;

    }

    static protected function setFileMetadataFromGmailApiEmailAttachmentFileInstanceMetadata(
        \propel\models\File &$file,
        \propel\models\FileInstance $fileInstance
    ) {

        $data = json_decode($fileInstance->getDataJson());

        $file->setSize($data->gmailApiParsedAttachmentMetadata->filesize);
        $file->setMimetype($data->gmailApiParsedAttachmentMetadata->contentType);
        $file->setFilename($data->gmailApiParsedAttachmentMetadata->filename);
        $file->setOriginalFilename($data->gmailApiParsedAttachmentMetadata->filename);

    }

}
