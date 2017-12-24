<?php

namespace neam\stateless_file_management\file_storage_components;

interface FileStorage
{

    public function absoluteUrl();

    /** Returns the (possibly remotely located) binary data */
    public function fileContents();

    public function deliverFileContentsAsHttpResponse();

}
