<?php

class VeronaFile {
    public static $sizes = array('Bytes', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB');
    public $size = 0;
    public $sizeStr = '0';
    public $fileDate = 0;
    public $fileDateStr = 'n/a';
    public $filename = '';
    public $version = '';
    public $apiVersion = '';
    public $name = '';
    public $description = '';
    public $isPlayer = false;
    public $isEditor = false;
    public $label = '';
    public $id = '';
    public $errorMessage = '';

    public function __construct($fullFilename) {
        $this->filename = basename($fullFilename);
        $this->fileDate = filemtime($fullFilename);
        if ($this->fileDate > 0) {
            $this->fileDateStr = date('d.m.Y H:i', $this->fileDate);
        }
        $this->size = filesize($fullFilename);
        if ($this->size > 0) {
            $this->sizeStr = round($this->size/pow(1024, ($i = floor(log($this->size, 1024)))), 2) . ' ' . VeronaFile::$sizes[$i];
        }
        $fileContent = file_get_contents($fullFilename);
        $document = new DOMDocument();
        $document->loadHTML($fileContent, LIBXML_NOERROR);
        $meta = VeronaFile::getMetadata($document, 'de');
        foreach ($meta as $key => $value) {
            if (!$value) {
                unset($meta[$key]);
            }
        }
        if (!$meta['title']) {
            $this->errorMessage = 'meta-information for this player not found.';
            return;
        }
        if ($meta['apiVersion'] && $meta['version'] && $meta['name']) {
            $versionMatches = null;
            $regexReturn = preg_match_all('/\d+/', $meta['version'], $versionMatches);
            if ($regexReturn && (count($versionMatches) > 0) && (count($versionMatches[0]) > 2)) {
                if ($meta['moduleType'] == 'editor') {
                    $this->isEditor = true;
                } else {
                    // players do not carry type attribute up to verona version 3.0
                    $this->isPlayer = true;
                }
                $this->name = strtolower(trim($meta['name']));
                $this->version = strtolower(trim($meta['version']));
                $this->apiVersion = strtolower(trim($meta['apiVersion']));
                $this->id = $this->name . '@' . $versionMatches[0][0] . '.' . $versionMatches[0][1];
                $this->label = $meta['title'] . ' v' . $versionMatches[0][0] . '.' . $versionMatches[0][1];
                $this->description = $meta['description'];
            } else {
                $this->errorMessage = '`data-version` attribute not semver format as expected (' . $meta['version'] . ').';
            }
        } else {
            $this->errorMessage = 'Missing `data-api-version` and/or `data-version` attribute in meta-information!';
        }
    }

    private static function getMetadata(DOMDocument $document, string $lang): array {
        $metadata = [];
        $metadata['title'] = '';
        $metadata['name'] = '';
        $metadata['description'] = '';
        $metadata['version'] = '';
        $metadata['moduleType'] = '';
        $metadata['apiVersion'] = '';
        $xpath = new DOMXpath($document);
        $jsonScripts = $xpath->query( '//script[@type="application/ld+json"]' );
        if( $jsonScripts->length > 0 ) {
            $json = trim( $jsonScripts->item(0)->nodeValue );
            $data = json_decode( $json, true );
            $metadata['title'] = $data['name'][$lang];
            if (!$metadata['title']) $metadata['title'] = $data['name']['en'];
            $metadata['name'] = $data['@id'];
            $metadata['version'] = $data['version'];
            $metadata['moduleType'] = $data['@type'];
            $metadata['apiVersion'] = $data['apiVersion'];
            $metadata['description'] = $data['description'][$lang];
            if (!$metadata['description']) $metadata['description'] = $data['description']['en'];
        } else {
            $titleElements = $document->getElementsByTagName('title');
            if (count($titleElements) > 0) {
                $titleElement = $titleElements[0];
                $metadata['title'] = $titleElement->textContent;
            }
            $metaElements = $document->getElementsByTagName('meta');
            if (count($metaElements) > 0) {
                foreach ($metaElements as $metaElement) { /* @var $metaElement DOMElement */
                    if ($metaElement->getAttribute('name') == 'application-name') {
                        $metadata['name'] = $metaElement->getAttribute('content');
                        $metadata['version'] = $metaElement->getAttribute('data-version');
                        $metadata['moduleType'] = $metaElement->getAttribute('data-module-type');
                        if ($metadata['moduleType']) $metadata['moduleType'] = substr($metadata['moduleType'], 7);
                        $metadata['apiVersion'] = $metaElement->getAttribute('data-api-version');
                    }
                }
            }
        }
        return $metadata;
    }
}

