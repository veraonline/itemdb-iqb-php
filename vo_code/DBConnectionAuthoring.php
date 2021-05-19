<?php
// www.IQB.hu-berlin.de
// Bărbulescu, Stroescu, Mechtel
// 2018
// license => MIT

require_once('DBConnection.php');

class DBConnectionAuthoring extends DBConnection
{
    private function fetchUnitById(int $unitId): array
    {
        $stmt = $this->pdoDBhandle->prepare(
            'SELECT * FROM units WHERE id = :id'
        );

        $stmt->bindParam(':id', $unitId);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function fetchUnitKeyById(int $unitId): string
    {
        $stmt = $this->pdoDBhandle->prepare(
            'SELECT key FROM units WHERE id = :id'
        );
        $stmt->bindParam(':id', $unitId);
        $stmt->execute();

        return $stmt->fetchColumn();
    }

    /**
     * @param int $workspaceId
     * @param string $unitKey
     * @return bool
     */
    private function checkUniqueWorkspaceUnitKey(int $workspaceId, string $unitKey): bool
    {
        $key = strtolower(trim($unitKey));

        $stmt = $this->pdoDBhandle->prepare(
            'SELECT count(*) FROM units WHERE workspace_id = :ws_id AND LOWER(key) = :key'
        );
        $stmt->execute(
            array(
                ':ws_id' => $workspaceId,
                ':key' => $key
            )
        );

        return $stmt->fetchColumn() == 0;
    }


    /**
     * Creates an upload directory at the passed upload path
     *
     * @param string $uploadPath <p>
     * Server upload directory path
     * </p>
     * @throws ErrorException with code 500, if upload directory already exists
     */
    private function createUploadDir(string $uploadPath): void
    {
        if (!file_exists($uploadPath)) {
            if (!mkdir($uploadPath)) {
                $message = "Upload directory could not be created.";
                error_log($message);
                throw new ErrorException($message, 500);
            }
        }
    }

    /**
     * Reads an upload directory at the passed upload path and returns all files with the passed file type extension
     * it contains.
     *
     * @param string $uploadPath <p>
     * Server upload directory path
     * </p>
     * @param string $fileType <p>
     * Optional file type extension filter
     * </p>
     * @return array of files of passed file type
     * @throws ErrorException with code 500, if upload directory does not exist
     */
    private function scanUploadDir(string $uploadPath, string $fileType): array
    {
        if (!(file_exists($uploadPath) && is_dir($uploadPath))) {
            throw new ErrorException("Upload directory does not exist!", 500);

        } else {
            $fileTypeSearchPattern = empty($fileType) ? "*" : "*." . $fileType;
            $files = glob($uploadPath . $fileTypeSearchPattern);

            if (count($files) == 0) {
                error_log("No unit metadata file found in upload directory!");
            }

            return $files;
        }
    }

    public function getUnitListByWorkspace(int $wsId): array
    {
        $myreturn = [];
        if (($this->pdoDBhandle != false) and ($wsId > 0)) {
            $sql = $this->pdoDBhandle->prepare(
                'SELECT units.id, units.key, units.label FROM units
                    WHERE units.workspace_id =:ws
                    ORDER BY units.key');

            if ($sql->execute(array(
                ':ws' => $wsId))) {

                $data = $sql->fetchAll(PDO::FETCH_ASSOC);
                if ($data != false) {
                    $myreturn = $data;
                }
            }
        }
        return $myreturn;
    }

    /**
     * @param string $sessionId Unique session identifier
     * @return array
     * <p>An Array of workspaces for the user associated with a valid session.</p>
     * <p>An empty array, if there are no workspaces for the user or the session is invalid.</p>
     */
    public function getWorkspaceList(string $sessionId): array
    {
        if ($this->checkSession($sessionId)) {
            $stmt = $this->pdoDBhandle->prepare("
                SELECT workspaces.id, workspaces.name, workspace_groups.id as ws_group_id, workspace_groups.name as ws_group_name
                FROM workspaces
                INNER JOIN workspace_groups ON workspaces.group_id = workspace_groups.id
                INNER JOIN workspace_users ON workspaces.id = workspace_users.workspace_id
                INNER JOIN users ON workspace_users.user_id = users.id
                INNER JOIN sessions ON  users.id = sessions.user_id
                WHERE sessions.token = :sessionId
                ORDER BY workspaces.name
            ");

            $stmt->execute([':sessionId' => $sessionId]);

            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        return $data ?? [];
    }

    // / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / /
    // returns the name of the workspace given by id
    // returns '' if not found
    // token is not refreshed
    public function getWorkspaceData(int $workspace_id): array
    {
        $myReturn = '';
        if ($this->pdoDBhandle != false) {

            $sql = $this->pdoDBhandle->prepare(
                'SELECT workspaces.id, workspaces.name as label, workspaces.settings as settings,
                            workspace_groups.name as group FROM workspaces
                          INNER JOIN workspace_groups ON workspaces.group_id = workspace_groups.id
                    WHERE workspaces.id=:w');

            if ($sql->execute(array(
                ':w' => $workspace_id))) {

                $data = $sql->fetch(PDO::FETCH_ASSOC);
                if ($data != false) {
                    $myReturn = $data;
                }
            }
        }

        return $myReturn;
    }

    public function addUnit(int $workspaceId, string $unitKey, ?string $unitLabel, ?string $editor, ?string $player): string
    {
        if (!$this->checkUniqueWorkspaceUnitKey($workspaceId, $unitKey)) {
            throw new Exception("Unit key already exists in workspace (Id: $workspaceId)", 406);
        }

        $sql = $this->pdoDBhandle->prepare(
            'INSERT INTO units (workspace_id, key, label, authoringtool_id, player_id, lastchanged) 
                    VALUES (:workspace, :key, :label, :editor, :player, :now)'
        );

        $sql->execute(
            array(
                ':workspace' => $workspaceId,
                ':key' => $unitKey,
                ':label' => $unitLabel,
                ':editor' => $editor,
                ':player' => $player,
                ':now' => date('Y-m-d G:i:s', time()
                )
            )
        );

        return $this->pdoDBhandle->lastInsertId();
    }

    /**
     * @param int $workspaceId
     * @param array $unit
     * @return bool true if db insert is successful, otherwise false
     * @throws ErrorException with code 406, if a workspace unit with the same key already exists
     */
    public function createUnit(int $workspaceId, array $unit): bool
    {
        if (empty($workspaceId) || empty($unit)) {
            return false;

        } else {
            if (!$this->checkUniqueWorkspaceUnitKey($workspaceId, $unit["key"])) {
                throw new ErrorException("Unit key already exists in workspace (Id: $workspaceId)", 406);
            }

            $sql = $this->pdoDBhandle->prepare(
                'INSERT INTO units (workspace_id, key, label, lastchanged, description, def, authoringtool_id, player_id, defref) ' .
                'VALUES (:workspace, :key, :label, :lastChange, :description, :definition, :editor_id, :player_id, :def_type)'
            );

            return $sql->execute(
                array(
                    ":workspace" => $workspaceId,
                    ":key" => $unit["key"],
                    ":label" => $unit["label"],
                    ":lastChange" => $unit["lastChange"],
                    ":description" => $unit["description"],
                    ":definition" => $unit["def"],
                    ":editor_id" => $unit["editor"],
                    ":player_id" => $unit["player"],
                    ":def_type" => $unit["defType"]
                )
            );
        }
    }

    /**
     * @param int $targetWorkspaceId
     * @param string $targetUnitKey
     * @param string $targetUnitLabel
     * @param string $sourceUnitId
     * @return string
     * @throws Exception
     */
    public function copyUnit(int $targetWorkspaceId, string $targetUnitKey, ?string $targetUnitLabel, string $sourceUnitId): string
    {
        if (!$this->checkUniqueWorkspaceUnitKey($targetWorkspaceId, $targetUnitKey)) {
            throw new Exception("Unit key already exists in workspace (Id: $targetWorkspaceId)", 406);
        }

        $sourceUnit = $this->fetchUnitById($sourceUnitId);
        if (!$sourceUnit) {
            return false;
        } else {
            $sql = $this->pdoDBhandle->prepare(
                'INSERT INTO units (workspace_id, key, label, lastchanged, description, def, authoringtool_id, player_id, defref) ' .
                'VALUES (:workspace, :key, :label, :now, :description, :def, :atId, :pId, :defref)'
            );

            $sql->execute(
                array(
                    ':workspace' => $targetWorkspaceId,
                    ':key' => $targetUnitKey,
                    ':label' => $targetUnitLabel,
                    ':now' => date('Y-m-d G:i:s', time()),
                    ':description' => $sourceUnit['description'],
                    ':def' => $sourceUnit['def'],
                    ':atId' => $sourceUnit['authoringtool_id'],
                    ':pId' => $sourceUnit['player_id'],
                    ':defref' => $sourceUnit['defref']
                )
            );
            return $this->pdoDBhandle->lastInsertId();
        }
    }

    public function deleteUnits(int $workspaceId, array $unitIds): bool
    {
        $myreturn = false;
        $sql = $this->pdoDBhandle->prepare(
            'DELETE FROM units
                WHERE units.workspace_id = :ws and units.id in (' . implode(',', $unitIds) . ')');

        if ($sql->execute(array(
            ':ws' => $workspaceId))) {

            $myreturn = true;
        }

        return $myreturn;
    }

    public function moveUnits(int $targetWorkspaceId, array $unitIds): array
    {
        $unmovableUnits = array();

        foreach ($unitIds as $unitId) {
            $unitKey = $this->fetchUnitKeyById($unitId);

            if (!empty($unitKey)) {
                if (!$this->checkUniqueWorkspaceUnitKey($targetWorkspaceId, $unitKey)) {
                    array_push($unmovableUnits, $this->fetchUnitById($unitId));

                } else {
                    $sql_update = $this->pdoDBhandle->prepare(
                        'UPDATE units SET workspace_id =:ws WHERE id =:id'
                    );
                    $sql_update->execute(
                        array(
                            ':ws' => $targetWorkspaceId,
                            ':id' => $unitId
                        )
                    );
                }
            }
        }

        return $unmovableUnits;
    }

    // filename will be unit key plus extension '.xml'; but if def is big there will be a second file
    // with extension '.voud'
    public function writeUnitDefToZipFile(int $wsId, int $unitId, ZipArchive $targetZip, int $maxDefSize): bool
    {
        $myreturn = false;
        if (($this->pdoDBhandle != false) and ($wsId > 0)) {
            $sql = $this->pdoDBhandle->prepare(
                'SELECT * FROM units
                    WHERE units.id =:id and units.workspace_id=:ws');

            if ($sql->execute(array(
                ':ws' => $wsId,
                ':id' => $unitId))) {

                $data = $sql->fetch(PDO::FETCH_ASSOC);
                if ($data != false) {
                    $xRootElement = new SimpleXMLElement(
                        '<Unit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation=' .
                        '"https://raw.githubusercontent.com/iqb-berlin/testcenter-backend/9.2.0/definitions/vo_Unit.xsd" />'
                    );
                    $xMetadataElement = $xRootElement->addChild('Metadata');
                    $xMetadataElement->addChild('Id', $data['key']);
                    $xMetadataElement->addChild('Label', $data['label']);
                    setlocale(LC_TIME, "de_DE");
                    $xMetadataElement->addChild('Description', $data['description']);
                    $xMetadataElement->addChild('Lastchange', date(DateTime::RFC3339, strtotime($data['lastchanged'])));
                    $xDefElement = null;
                    if (strlen($data['def']) > $maxDefSize) {
                        $xDefElement = $xRootElement->addChild('DefinitionRef', $data['key'] . '.voud');
                        $targetZip->addFromString($data['key'] . '.voud', $data['def']);
                    } else {
                        $xDefElement = $xRootElement->addChild('Definition', $data['def']);
                    }
                    $xDefElement->addAttribute('player', $data['player_id']);
                    $xDefElement->addAttribute('editor', $data['authoringtool_id']);
                    $xDefElement->addAttribute('type', $data['defref']);

                    $xfile = dom_import_simplexml($xRootElement)->ownerDocument;
                    $xfile->formatOutput = true;
                    $targetZip->addFromString($data['key'] . '.xml', $xfile->saveXML());
                    $myreturn = true;
                }
            }
        }

        return $myreturn;
    }

    public function getUnitMetadata($unitId)
    {
        $myreturn = [];
        if (($this->pdoDBhandle != false) and ($unitId > 0)) {
            $sql = $this->pdoDBhandle->prepare(
                'SELECT units.id, units.key, units.label, units.description, 
                    units.lastchanged as lastchanged, units.authoringtool_id as editorid,
                    units.player_id as playerid, units.defref as deftype FROM units
                    WHERE units.id =:u');

            if ($sql->execute(array(
                ':u' => $unitId))) {

                $data = $sql->fetch(PDO::FETCH_ASSOC);
                if ($data != false) {
                    $myreturn = $data;
                    try {
                        $pdo_timestamp = $data['lastchanged'];
                        $php_datetime = new DateTimeImmutable($pdo_timestamp);
                        $myreturn['lastchanged'] = $php_datetime->getTimestamp();
                    } catch (Exception $e) {
                        $myreturn['lastchanged'] = 0;
                    }
                }
            }
        }
        return $myreturn;
    }

    // todo delete
    public function getUnitDesignData($wsId, $unitId)
    {
        $myreturn = [];
        if (($this->pdoDBhandle != false) and ($wsId > 0)) {
            $sql = $this->pdoDBhandle->prepare(
                'SELECT units.id, units.key, units.label, units.def, units.authoringtool_id, units.player_id FROM units
                    WHERE units.id =:id and units.workspace_id=:ws');

            if ($sql->execute(array(
                ':ws' => $wsId,
                ':id' => $unitId))) {

                $data = $sql->fetch(PDO::FETCH_ASSOC);
                if ($data != false) {
                    $myreturn = $data;
                }
            }
        }
        return $myreturn;
    }

    public function getUnitDefinition($unitId)
    {
        $myreturn = '';
        if (($this->pdoDBhandle != false) and ($unitId > 0)) {
            $sql = $this->pdoDBhandle->prepare(
                'SELECT units.def FROM units WHERE units.id =:u');

            if ($sql->execute(array(
                ':u' => $unitId))) {

                $data = $sql->fetch(PDO::FETCH_ASSOC);
                if ($data != false) {
                    $myreturn = $data['def'];
                }
            }
        }
        return $myreturn;
    }

    public function getUnitPreviewData($wsId, $unitId)
    {
        $myreturn = [];
        if (($this->pdoDBhandle != false) and ($wsId > 0)) {
            $sql = $this->pdoDBhandle->prepare(
                'SELECT units.id, units.key, units.label, units.def, units.player_id FROM units
                    WHERE units.id =:id and units.workspace_id=:ws');

            if ($sql->execute(array(
                ':ws' => $wsId,
                ':id' => $unitId))) {

                $data = $sql->fetch(PDO::FETCH_ASSOC);
                if ($data != false) {
                    $myreturn = $data;
                }
            }
        }
        return $myreturn;
    }

    public function getUnitAuthoringTool($unitId)
    {
        $myreturn = '';
        if (($this->pdoDBhandle != false) and ($unitId > 0)) {
            $sql = $this->pdoDBhandle->prepare(
                'SELECT units.authoringtool_id FROM units
                    WHERE units.id =:id');

            if ($sql->execute(array(
                ':id' => $unitId))) {

                $data = $sql->fetch(PDO::FETCH_ASSOC);
                if ($data != false) {
                    $myreturn = $data['authoringtool_id'];
                }
            }
        }
        return $myreturn;
    }

    public function getUnitItemPlayerId($unitId)
    {
        $myreturn = '';
        if (($this->pdoDBhandle != false) and ($unitId > 0)) {
            $sql = $this->pdoDBhandle->prepare(
                'SELECT units.player_id FROM units
                    WHERE units.id =:id');

            if ($sql->execute(array(
                ':id' => $unitId))) {

                $data = $sql->fetch(PDO::FETCH_ASSOC);
                if ($data != false) {
                    $myreturn = $data['player_id'];
                }
            }
        }
        return $myreturn;
    }

    /**
     * @param int $workspaceId
     * @param string $unitId
     * @param string $unitKey
     * @param string $unitLabel
     * @param string $unitDescription
     * @param string $player
     * @param string $editor
     * @param string $defType
     * @return bool
     * @throws Exception
     */
    public function changeUnitProps(
        int $workspaceId,
        string $unitId,
        string $unitKey,
        ?string $unitLabel,
        ?string $unitDescription,
        ?string $player,
        ?string $editor,
        ?string $defType): bool
    {
        $return = false;
        $newTrimmedKey = trim($unitKey);
        $originUnitKey = $this->fetchUnitKeyById($unitId);

        if (strtolower($originUnitKey) !== strtolower($newTrimmedKey)
            && !$this->checkUniqueWorkspaceUnitKey($workspaceId, $unitKey)) {
            throw new Exception("Unit key already exists in workspace (Id: $workspaceId)", 406);
        }

        $sql_update = $this->pdoDBhandle->prepare(
            'UPDATE units
            SET key=:k,
                label=:l,
                authoringtool_id=:e,
                description=:ds,
                defref=:dt,
                player_id=:p
            WHERE id =:id');

        if ($sql_update != false) {
            $return = $sql_update->execute(array(
                ':id' => $unitId,
                ':e' => $editor,
                ':k' => $newTrimmedKey,
                ':l' => $unitLabel,
                ':ds' => $unitDescription,
                ':dt' => $defType,
                ':p' => $player
            ));
        }

        return $return;
    }

    public function changeUnitLastChange($unitId, $lastChange): bool
    {
        $return = false;
        $lastChangeNumber = strtotime($lastChange);
        $sql_update = $this->pdoDBhandle->prepare(
            'UPDATE units
            SET lastchanged=:l
            WHERE id =:id');

        if ($sql_update != false) {
            $return = $sql_update->execute(array(
                ':id' => $unitId,
                ':l' => date('Y-m-d G:i:s', $lastChangeNumber)
            ));
        }
        return $return;
    }

    public function setUnitEditor(int $unitId, ?string $editorId): bool
    {
        $myreturn = false;
        $sql_update = $this->pdoDBhandle->prepare(
            'UPDATE units
                SET editor_id =:e, lastchanged=:now
                WHERE id =:u');

        if ($sql_update != false) {
            $myreturn = $sql_update->execute(array(
                ':u' => $unitId,
                ':e' => $editorId,
                ':now' => date('Y-m-d G:i:s', time())
            ));
        }
        return $myreturn;
    }

    public function setUnitDefinition(int $unitId, ?string $myUnitdef): bool
    {
        $myreturn = false;
        $sql_update = $this->pdoDBhandle->prepare(
            'UPDATE units
                SET def =:ud, lastchanged=:now
                WHERE id =:id');

        if ($sql_update != false) {
            $myreturn = $sql_update->execute(array(
                ':id' => $unitId,
                ':ud' => $myUnitdef,
                ':now' => date('Y-m-d G:i:s', time())
            ));
        }
        return $myreturn;
    }

    public function setUnitPlayer($unitId, $playerId): bool
    {
        $stmt = $this->pdoDBhandle->prepare('UPDATE units SET player_id= :pl, lastchanged= :now WHERE id = :id');
        $params = array(
            ':id' => $unitId,
            ':pl' => $playerId,
            ':now' => date('Y-m-d G:i:s', time()));

        return $stmt->execute($params);
    }

    public function setWorkspaceSettings($workspaceId, $settings): bool
    {
        $stmt = $this->pdoDBhandle->prepare('UPDATE workspaces SET settings= :s WHERE id = :id');
        $params = array(
            ':id' => $workspaceId,
            ':s' => json_encode($settings));

        return $stmt->execute($params);
    }

    /**
     * @param array $phpUploadMetaData <p>
     * Array of file upload metadata
     * </p>
     * @throws ErrorException
     * <p>with code 400 if file upload has failed</p>
     * <p>with code 413 if file upload exceeds the maximum permitted size</p>
     */
    public function handleFileUpload(array $phpUploadMetaData): void
    {
        if (empty($phpUploadMetaData) || $phpUploadMetaData['error'] != UPLOAD_ERR_OK) {
            $fileUploadError = $phpUploadMetaData['error'];
            error_log("fileError = $fileUploadError");

            $errorMessage = 'File Upload failed.';
            $errorCode = 400;

            switch ($fileUploadError) {
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    $errorMessage = 'The file exceeds the maximum permitted size.';
                    $errorCode = 413;
                    break;

                case UPLOAD_ERR_NO_FILE:
                    $errorMessage = 'No file selected for upload.';
                    break;

                default:
                    break;
            }

            throw new ErrorException($errorMessage, $errorCode);
        }

    }

    /**
     * @param string $uploadPath <p>
     * Server upload directory path
     * </p>
     * @param string $uploadFileTmpName <p>
     * Upload file tmp name
     * </p>
     * @throws ErrorException
     * <p>with code 400, if the zip file is corrupt or cannot be opened</p>
     * <p>with code 500, if upload directory cannot be created</p>
     */
    function extractZipArchive(string $uploadPath, string $uploadFileTmpName): void
    {
        $zip = new ZipArchive;
        if ($zip->open($uploadFileTmpName) === true) {

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $archiveEntryName = $zip->getNameIndex($i);
                $fileInfo = pathinfo($archiveEntryName);

                $sourcePath = "zip://" . $uploadFileTmpName . "#" . $archiveEntryName;
                $targetPath = $uploadPath . $fileInfo['basename'];

                if ($fileInfo['extension'] == 'zip') {
                    error_log("zip in zip");
                }

                $this->createUploadDir($uploadPath);
                copy($sourcePath, $targetPath);
            }

            $zip->close();
        } else {
            throw new ErrorException('Could not extract Zip-File', 400);
        }

        unlink($uploadFileTmpName);
    }


    /**
     * @param string $uploadPath <p>
     * Server upload directory path
     * </p>
     * @param array $phpUploadMetaData <p>
     * Array of file upload metadata
     * </p>
     * @throws ErrorException
     * <p>with code 409, if file already exists</p>
     * <p>with code 500, if file cannot be saved</p>
     */
    function saveUnitFile(string $uploadPath, array $phpUploadMetaData): void
    {
        $unitFilename = $uploadPath . $phpUploadMetaData['name'];

        if (!file_exists($uploadPath)) {
            mkdir($uploadPath);
        }

        if (file_exists($unitFilename)) {
            throw new ErrorException(
                "Uploaded unit file '" . $phpUploadMetaData['name'] . "' already exists!", 409);
        }

        if (!move_uploaded_file($phpUploadMetaData['tmp_name'], $unitFilename)) {
            throw new ErrorException("File '$unitFilename' cannot be saved!", 500);

        } else {
            error_log("File '$unitFilename' saved.");
        }
    }

    /**
     * @param string $uploadPath <p>
     * Server upload directory path
     * </p>
     * @param string $metadataFileType <p>[optional]</p><p>
     * Unit metadata file type (default: 'xml')
     * </p>
     * @return array of unit import data
     * @throws ErrorException with code 500, if upload directory does not exist
     */
    public function fetchUnitImportData(string $uploadPath, string $metadataFileType = "xml"): array
    {
        $importData = array();

        $metadataFiles = $this->scanUploadDir($uploadPath, $metadataFileType);

        foreach ($metadataFiles as $metadataFile) {
            array_push($importData, $this->readUnitMetadataFile($metadataFile, $uploadPath));
        }

        return $importData;
    }

    /**
     * @param string $metadataFile <p>
     * Path of uploaded unit metadata file
     * </p>
     * @param string $uploadPath <p>
     * Server upload directory path
     * </p>
     * @return array of unit import data
     */
    public function readUnitMetadataFile(string $metadataFile, string $uploadPath): array
    {
        $result = array(
            "filename" => pathinfo($metadataFile, PATHINFO_BASENAME),
            "unit" => array(),
            "message" => ""
        );

        $xml = simplexml_load_file($metadataFile);

        if (!$xml) {
            $result["message"] = "XML-Struktur der Metadatendatei ist beschädigt";

        } else {
            $this->parseXML($xml, $uploadPath, $result);
        }

        error_log("RESULT = " . json_encode($result));

        return $result;
    }

    /**
     * @param SimpleXMLElement|string $xml <p>
     * Unit XML Document
     * </p>
     * @param string $uploadPath <p>
     * Server upload directory path
     * </p>
     * @param array $result <p>
     * Referenced array of unit import data
     * </p>
     */
    private function parseXML($xml, string $uploadPath, array &$result): void
    {
        $idNodes = $xml->xpath('/Unit/Metadata/Id');
        $labelNodes = $xml->xpath('/Unit/Metadata/Label');
        $descriptionNodes = $xml->xpath('/Unit/Metadata/Description');
        $lastChangeNodes = $xml->xpath('/Unit/Metadata/Lastchange');
        $definitionRefNodes = $xml->xpath('/Unit/DefinitionRef');
        $definitionNodes = $xml->xpath('/Unit/Definition');

        $idNodesCount = count($idNodes);
        $labelNodesCount = count($labelNodes);
        $descriptionNodesCount = count($descriptionNodes);
        $lastChangeNodesCount = count($lastChangeNodes);
        $definitionNodesCount = count($definitionNodes);
        $definitionRefNodesCount = count($definitionRefNodes);

        if (
            $idNodesCount != 1 ||                                           // mandatory node
            $labelNodesCount > 1 ||                                         // optional node
            $descriptionNodesCount > 1 ||                                   // optional node
            $lastChangeNodesCount > 1 ||                                    // optional node
            ($definitionNodesCount != 1 && $definitionRefNodesCount != 1)   // mandatory optional nodes
        ) {
            error_log("Unit XML is invalid!");
            $xmlErrorMessage = "XML fehlerhaft: ";
            $validationMessages = array();

            // Check XML Element minimum occurrence
            $minOccMsg = "Element '%s' fehlt";
            if ($idNodesCount == 0) {
                array_push(
                    $validationMessages,
                    sprintf($minOccMsg, "Id")
                );
            }

            if ($definitionNodesCount != 1 && $definitionRefNodesCount != 1) {
                if ($definitionNodesCount == 0) {
                    array_push(
                        $validationMessages,
                        sprintf($minOccMsg, "Definition")
                    );
                }
                if ($definitionRefNodesCount == 0) {
                    array_push(
                        $validationMessages,
                        sprintf($minOccMsg, "DefinitionRef")
                    );
                }
            }

            // Check XML Element maximum occurrence
            $maxOccMsg = "Element '%s' existiert %d-mal zuviel";
            if ($idNodesCount > 1) {
                array_push(
                    $validationMessages,
                    sprintf($maxOccMsg, "Id", $idNodesCount - 1)
                );
            }
            if ($labelNodesCount > 1) {
                array_push(
                    $validationMessages,
                    sprintf($maxOccMsg, "Label", $labelNodesCount - 1)
                );
            }
            if ($descriptionNodesCount > 1) {
                array_push(
                    $validationMessages,
                    sprintf($maxOccMsg, "Description", $descriptionNodesCount - 1)
                );
            }
            if ($lastChangeNodesCount > 1) {
                array_push(
                    $validationMessages,
                    sprintf($maxOccMsg, "Lastchange", $lastChangeNodesCount - 1)
                );
            }
            if ($definitionNodesCount > 1) {
                array_push(
                    $validationMessages,
                    sprintf($maxOccMsg, "Definition", $definitionNodesCount - 1)
                );
            }
            if ($definitionRefNodesCount > 1) {
                array_push(
                    $validationMessages,
                    sprintf($maxOccMsg, "DefinitionRef", $definitionRefNodesCount - 1)
                );
            }

            $result["message"] = $xmlErrorMessage . implode(", ", $validationMessages) . ".";
            error_log("RESULT = " . json_encode($result));

        } else {
            $unit = array(
                "key" => (string)$idNodes[0],
                "label" => (string)$labelNodes[0],
                "description" => (string)$descriptionNodes[0],
                "lastChange" => empty($lastChangeNodes[0]) ? date("Y-m-d G:i:s") : (string)$lastChangeNodes[0],
                "editor" => "",
                "player" => "",
                "defType" => "",
                "def" => ""
            );

            if (count($definitionRefNodes) == 1) {
                $unitDefinitionFile = $uploadPath . $definitionRefNodes[0];
                $unit['editor'] = (string)$definitionRefNodes[0]['editor'];
                $unit['player'] = (string)$definitionRefNodes[0]['player'];
                $unit['defType'] = (string)$definitionRefNodes[0]['type'];

                try {
                    $unit['def'] = $this->readUnitDefinitionFile($unitDefinitionFile);

                } catch (ErrorException $exception) {
                    error_log($exception->getMessage());

                    unset($result["unit"]);
                    switch ($exception->getCode()) {
                        case 404:
                            $result["message"] = "Definitionsdatei nicht vorhanden";
                            break;

                        case 422:
                            $result["message"] = "Definitionsdatei konnte nicht gelesen werden";
                            break;

                        default:
                            $result["message"] = "Definitionsdatei ist fehlerhaft";
                            break;
                    }
                }

            } else {
                $unit['editor'] = (string)$definitionNodes[0]['editor'];
                $unit['player'] = (string)$definitionNodes[0]['player'];
                $unit['defType'] = (string)$definitionNodes[0]['type'];
                $unit['def'] = (string)$definitionNodes[0];
            }

            $unit = $this->mapIqbPlayer($unit);

            $result["unit"] = $unit;
        }
    }

    /**
     * @param string $unitDefinitionFile <p>
     * Path of uploaded unit definition file
     * </p>
     * @return string the unit definition data
     * @throws ErrorException
     * <p>with code 404, if unit definition file does not exist</p>
     * <p>with code 422, if unit definition file cannot be read</p>
     */
    public function readUnitDefinitionFile(string $unitDefinitionFile): string
    {
        if (!file_exists($unitDefinitionFile)) {
            throw new ErrorException("Unit definition file does not exist!", 404);

        } else {
            $definition = file_get_contents($unitDefinitionFile);

            if (!$definition) {
                throw new ErrorException("Cannot read unit definition file $unitDefinitionFile!", 422);
            }
        }

        return $definition;
    }

    /**
     * Maps deprecated IQB Player notation
     * @param array $unit
     * @return array Unit with new notations for 'editor', 'player', and 'defType'
     */
    public function mapIqbPlayer(array $unit): array
    {
        if (!empty($unit) &&
            ($unit['player'] == 'IQBVisualUnitPlayerV2' || $unit['player'] == 'IQBVisualUnitPlayerV1')) {
            $unit['editor'] = 'iqb-editor-dan@3.0';
            $unit['player'] = 'iqb-player-dan@3.0';
            $unit['defType'] = 'iqb-player-dan@3.0';
        }
        return $unit;
    }

    /**
     * @param int $workspaceId <p>
     * Unique workspace identifier
     * </p>
     * @param array $importData <p>
     * Array of unit import data
     * </p>
     * @return array Array of unit import data enriched with db operations information
     */
    public function saveUnitImportData(string $workspaceId, array $importData): array
    {
        $result = [];
        foreach ($importData as $import) {
            $importResult = array(
                "filename" => $import["filename"],
                "success" => false,
                "message" => ""
            );

            if (empty($import["unit"])) {
                $importResult["message"] = $import["message"];

            } else {
                try {
                    if ($this->createUnit($workspaceId, $import["unit"])) {
                        $importResult["success"] = true;
                        $importResult["message"] = "Aufgabe erfolgreich importiert.";

                    } else {
                        $importResult["message"] = "Konnte Aufgabe nicht importieren (createUnit).";
                    }

                } catch (ErrorException $exception) {
                    switch ($exception->getCode()) {
                        case 406:
                            $importResult["message"] = "Kurzname der Aufgabe ist bereits vorhanden.";
                            break;

                        default:
                            $importResult["message"] = "Konnte Aufgabe nicht importieren (exception).";
                            break;
                    }
                }
            }

            array_push($result, $importResult);
        }

        return $result;
    }

    /**
     * @param string $uploadPath <p>
     * Server upload directory path
     * </p>
     * @return bool true, if upload directory and all contents could be removed, otherwise false
     */
    public function cleanUpImportDirectory(string $uploadPath): bool
    {
        $isSuccessful = false;

        if (file_exists($uploadPath) && is_dir($uploadPath)) {
            $files = array_diff(scandir($uploadPath), array('.', '..'));

            foreach ($files as $file) {
                is_dir("$uploadPath/$file")
                    ? $this->cleanUpImportDirectory("$uploadPath/$file")
                    : unlink("$uploadPath/$file");
            }

            $isSuccessful = rmdir($uploadPath);

        } else {
            error_log("Directory at $uploadPath does not exist!");
        }

        return $isSuccessful;
    }
}
