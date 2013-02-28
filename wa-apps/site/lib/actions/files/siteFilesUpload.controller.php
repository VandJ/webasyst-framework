<?php

class siteFilesUploadController extends waUploadJsonController
{

    protected function process()
    {
        parent::process();
        $this->log('file_upload', 1);
    }

    protected function getPath()
    {
        $path = rtrim(waRequest::post('path'), ' /');
        return wa()->getDataPath($path, true);
    }    
}