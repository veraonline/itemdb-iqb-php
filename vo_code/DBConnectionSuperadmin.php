<?php
// www.IQB.hu-berlin.de
// Bărbulescu, Stroescu, Mechtel
// 2018
// license: MIT

require_once('DBConnection.php');

class DBConnectionSuperAdmin extends DBConnection
{
    private static $_tempfilepath = '../vo_tmp';

    public static function getTempFilePath()
    {
        $myreturn = '';
        $myfolder = DBConnectionSuperAdmin::$_tempfilepath;
        if (file_exists($myfolder)) {
            $myreturn = $myfolder;
        } else {
            if (mkdir($myfolder)) {
                $myreturn = $myfolder;
            }
        }
        return $myreturn;
    }

    /**
     * @param string $sessionToken Session token (refreshed via 'isSuperAdmin' function call)
     * @return null|array
     * <p>Null, if session token is invalid, user is not a super admin,</p>
     * <p>otherwise an array of all workspace id, workspace name, workspace group id, and workspace group name quadruples</p>
     */
    public function getWorkspaces(?string $sessionToken): ?array
    {
        if (!empty($sessionToken) && $this->isSuperAdmin($sessionToken)) {
            $query = "
                        SELECT workspaces.id as id,
                               workspaces.name as label,
                               workspace_groups.id as ws_group_id,
                               workspace_groups.name as ws_group_name
                        FROM workspaces
                            INNER JOIN workspace_groups ON workspaces.group_id = workspace_groups.id
                        ORDER BY workspace_groups.name, workspaces.name
                        ";
            $stmt = $this->pdoDBhandle->prepare($query);
            $stmt->execute();
            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        return $result ?? null;
    }

    public function getWorkspaceGroups($token)
    {
        $myreturn = [];
        if ($this->isSuperAdmin($token)) {
            $sql = $this->pdoDBhandle->prepare(
                'SELECT workspace_groups.id as id,
                            workspace_groups.name as label,
                            COUNT(workspaces.id) as ws_count FROM workspace_groups
                        LEFT JOIN workspaces ON workspaces.group_id = workspace_groups.id
                        GROUP BY workspace_groups.id
                        ORDER BY 1');

            if ($sql->execute()) {

                $data = $sql->fetchAll(PDO::FETCH_ASSOC);
                if ($data != false) {
                    $myreturn = $data;
                }
            }
        }
        return $myreturn;
    }

    // / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / /
    // returns the name of the workspace given by id
    // returns '' if not found
    // token is not refreshed
    public function getWorkspaceName($workspace_id)
    {
        $myreturn = '';
        if ($this->pdoDBhandle != false) {

            $sql = $this->pdoDBhandle->prepare(
                'SELECT workspaces.name FROM workspaces
                    WHERE workspaces.id=:workspace_id');

            if ($sql->execute(array(
                ':workspace_id' => $workspace_id))) {

                $data = $sql->fetch(PDO::FETCH_ASSOC);
                if ($data != false) {
                    $myreturn = $data['name'];
                }
            }
        }

        return $myreturn;
    }


    // / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / /
    // returns all users if the user associated with the given token is superadmin
    // returns [] if token not valid or no users 
    // token is refreshed via isSuperAdmin
    public function getUsers($token)
    {
        $myreturn = [];
        if ($this->isSuperAdmin($token)) {
            $sql = $this->pdoDBhandle->prepare(
                'SELECT users.name, users.id, users.is_superadmin FROM users ORDER BY users.name');

            if ($sql->execute()) {
                $data = $sql->fetchAll(PDO::FETCH_ASSOC);
                if ($data != false) {
                    $myreturn = $data;
                }
            }
        }
        return $myreturn;
    }

    public function setSuperAdminFlag($sessionToken, $userId, $superAdminPassword, $isSuperAdmin)
    {
        $return = false;

        if ($this->verifyCredentials($sessionToken, $superAdminPassword, true)) {
            $stmt = $this->pdoDBhandle->prepare(
                'UPDATE users SET is_superadmin = :super_admin_flag WHERE id = :user_id');
            $params = array(
                ':user_id' => $userId,
                ':super_admin_flag' => $isSuperAdmin
            );

            if ($stmt->execute($params)) {
                $return = true;
            } else {
                error_log("'is_superadmin' Update failed for user id $userId");
            }
        }

        return $return;
    }

    // / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / /
    // returns all workspaces with a flag whether the given user has access to it
    // returns [] if token not valid or user not found
    // token is refreshed via isSuperAdmin
    public function getWorkspacesByUser($token, $username)
    {
        $myreturn = [];
        if ($this->isSuperAdmin($token)) {
            $sql = $this->pdoDBhandle->prepare(
                'SELECT workspace_users.workspace_id as id FROM workspace_users
                    INNER JOIN users ON users.id = workspace_users.user_id
                    WHERE users.name=:user_name');

            if ($sql->execute(array(
                ':user_name' => $username))) {

                $userworkspaces = $sql->fetchAll(PDO::FETCH_ASSOC);
                $workspaceIdList = [];
                if ($userworkspaces != false) {
                    foreach ($userworkspaces as $userworkspace) {
                        array_push($workspaceIdList, $userworkspace['id']);
                    }
                }

                $sql = $this->pdoDBhandle->prepare(
                    'SELECT workspaces.id as id,
                                workspaces.name as label,
                                workspace_groups.id as ws_group_id,
                                workspace_groups.name as ws_group_name FROM workspaces 
                            INNER JOIN workspace_groups ON workspaces.group_id = workspace_groups.id
                            ORDER BY workspaces.name');

                if ($sql->execute()) {
                    $allworkspaces = $sql->fetchAll(PDO::FETCH_ASSOC);
                    if ($allworkspaces != false) {
                        foreach ($allworkspaces as $workspace) {
                            array_push($myreturn, [
                                'id' => $workspace['id'],
                                'label' => $workspace['label'],
                                'ws_group_id' => $workspace['ws_group_id'],
                                'ws_group_name' => $workspace['ws_group_name'],
                                'selected' => in_array($workspace['id'], $workspaceIdList)]);
                        }
                    }
                }
            }
        }
        return $myreturn;
    }


    // / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / /
    // sets workspaces to the given user to give access to it
    // returns false if token not valid or user not found
    // token is refreshed via isSuperAdmin
    public function setWorkspacesByUser($token, $username, $workspaces)
    {
        $myreturn = false;
        if ($this->isSuperAdmin($token)) {
            $sql = $this->pdoDBhandle->prepare(
                'SELECT users.id FROM users
                    WHERE users.name=:user_name');
            if ($sql->execute(array(
                ':user_name' => $username))) {
                $data = $sql->fetch(PDO::FETCH_ASSOC);
                if ($data != false) {
                    $userid = $data['id'];
                    $sql = $this->pdoDBhandle->prepare(
                        'DELETE FROM workspace_users
                            WHERE workspace_users.user_id=:user_id');

                    if ($sql->execute(array(
                        ':user_id' => $userid))) {

                        $sql_insert = $this->pdoDBhandle->prepare(
                            'INSERT INTO workspace_users (workspace_id, user_id) 
                                VALUES(:workspaceId, :userId)');
                        foreach ($workspaces as $userworkspace) {
                            if ($userworkspace['selected']) {
                                $sql_insert->execute(array(
                                    ':workspaceId' => $userworkspace['id'],
                                    ':userId' => $userid));
                            }
                        }
                        $myreturn = true;
                    }
                }
            }
        }
        return $myreturn;
    }

    // / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / /
    // sets password of the given user
    // returns false if token not valid or user not found
    // token is refreshed via isSuperAdmin
    public function setPassword($token, $username, $password)
    {
        $myreturn = false;
        if ($this->isSuperAdmin($token)) {
            $sql = $this->pdoDBhandle->prepare(
                'UPDATE users SET password = :password WHERE name = :user_name');
            if ($sql->execute(array(
                ':user_name' => $username,
                ':password' => $this->encryptPassword($password)))) {
                $myreturn = true;
            }
        }
        return $myreturn;
    }

    // / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / /
    // adds new user if no user with the given name exists
    // returns true if ok, false if admin-token not valid or user already exists
    // token is refreshed via isSuperAdmin
    public function addUser($token, $username, $password)
    {
        $myreturn = false;
        if ($this->isSuperAdmin($token)) {

            $sql = $this->pdoDBhandle->prepare(
                'SELECT users.name FROM users
                    WHERE users.name=:user_name');

            if ($sql->execute(array(
                ':user_name' => $username))) {

                $data = $sql->fetch(PDO::FETCH_ASSOC);
                if ($data == false) {
                    $sql = $this->pdoDBhandle->prepare(
                        'INSERT INTO users (name, password) VALUES (:user_name, :user_password)');

                    if ($sql->execute(array(
                        ':user_name' => $username,
                        ':user_password' => $this->encryptPassword($password)))) {

                        $myreturn = true;
                    }
                }
            }
        }

        return $myreturn;
    }

    // / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / /
    // deletes users
    // returns false if token not valid or any delete action failed
    // token is refreshed via isSuperAdmin
    public function deleteUsers($token, $usernames)
    {
        $myreturn = false;
        if ($this->isSuperAdmin($token)) {
            $sql = $this->pdoDBhandle->prepare(
                'DELETE FROM users
                    WHERE users.name = :user_name');

            $myreturn = true;
            foreach ($usernames as $username) {
                if (!$sql->execute(array(
                    ':user_name' => $username))) {
                    $myreturn = false;
                }
            }
        }
        return $myreturn;
    }

    // / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / /
    // / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / /
    // / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / /
    // / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / /
    public function addWorkspace($token, $name, $wsGroupId)
    {
        $myreturn = false;
        if ($this->isSuperAdmin($token)) {

            $sql = $this->pdoDBhandle->prepare(
                'SELECT workspaces.id FROM workspaces 
                    WHERE workspaces.name=:ws_name and workspaces.group_id=:ws_group_id');

            if ($sql->execute(array(
                ':ws_name' => $name, ':ws_group_id' => $wsGroupId))) {

                $data = $sql->fetch(PDO::FETCH_ASSOC);
                if ($data == false) {
                    $sql = $this->pdoDBhandle->prepare(
                        'INSERT INTO workspaces (name, group_id) VALUES (:ws_name, :ws_group_id)');

                    if ($sql->execute(array(
                        ':ws_name' => $name, ':ws_group_id' => $wsGroupId))) {

                        $myreturn = true;
                    }
                }
            }
        }

        return $myreturn;
    }

    // / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / /
    public function setWorkspace($token, $wsid, $name, $wsgId)
    {
        $myreturn = false;
        if ($this->isSuperAdmin($token)) {
            $sql = $this->pdoDBhandle->prepare(
                'UPDATE workspaces SET name = :name, group_id = :wsg WHERE id = :id');
            if ($sql->execute(array(
                ':name' => $name,
                ':wsg' => $wsgId,
                ':id' => $wsid))) {
                $myreturn = true;
            }
        }
        return $myreturn;
    }

    public function setWorkspaceGroup($token, $wsgId, $wsgName)
    {
        $myreturn = false;
        if ($this->isSuperAdmin($token)) {
            $sql = $this->pdoDBhandle->prepare(
                'UPDATE workspace_groups SET name = :name WHERE id = :id');
            if ($sql->execute(array(
                ':name' => $wsgName,
                ':id' => $wsgId))) {
                $myreturn = true;
            }
        }
        return $myreturn;
    }

    // / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / /
    public function deleteWorkspaces($token, $wsIds)
    {
        $myreturn = false;
        if ($this->isSuperAdmin($token)) {
            $sql = $this->pdoDBhandle->prepare(
                'DELETE FROM workspaces
                    WHERE workspaces.id = :ws_id');

            $myreturn = true;
            foreach ($wsIds as $wsId) {
                if (!$sql->execute(array(
                    ':ws_id' => $wsId))) {
                    $myreturn = false;
                }
            }
        }
        return $myreturn;
    }

    public function deleteWorkspaceGroup($token, $wsgId)
    {
        if ($this->isSuperAdmin($token)) {
            $sql = $this->pdoDBhandle->prepare(
                'DELETE FROM workspace_groups
                    WHERE workspace_groups.id = :wsg_id');

            return $sql->execute(array(':wsg_id' => $wsgId));
        }
        return false;
    }

    // / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / /
    public function getUsersByWorkspace($token, $wsId)
    {
        $myreturn = [];
        if ($this->isSuperAdmin($token)) {
            $sql = $this->pdoDBhandle->prepare(
                'SELECT workspace_users.user_id as id FROM workspace_users
                    WHERE workspace_users.workspace_id=:ws_id');

            if ($sql->execute(array(
                ':ws_id' => $wsId))) {

                $workspaceusers = $sql->fetchAll(PDO::FETCH_ASSOC);
                $userIdList = [];
                if ($workspaceusers != false) {
                    foreach ($workspaceusers as $workspaceuser) {
                        array_push($userIdList, $workspaceuser['id']);
                    }
                }

                $sql = $this->pdoDBhandle->prepare(
                    'SELECT users.id, users.name FROM users ORDER BY users.name');

                if ($sql->execute()) {
                    $allusers = $sql->fetchAll(PDO::FETCH_ASSOC);
                    if ($allusers != false) {
                        foreach ($allusers as $user) {
                            array_push($myreturn, [
                                'id' => $user['id'],
                                'label' => $user['name'],
                                'selected' => in_array($user['id'], $userIdList)]);
                        }
                    }
                }
            }
        }
        return $myreturn;
    }


    // / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / /
    public function setUsersByWorkspace($token, $wsId, $users)
    {
        $myreturn = false;
        if ($this->isSuperAdmin($token)) {

            $sql = $this->pdoDBhandle->prepare(
                'DELETE FROM workspace_users
                    WHERE workspace_users.workspace_id=:ws_id');

            if ($sql->execute(array(
                ':ws_id' => $wsId))) {

                $sql_insert = $this->pdoDBhandle->prepare(
                    'INSERT INTO workspace_users (workspace_id, user_id) 
                        VALUES(:workspaceId, :userId)');
                $myreturn = true;
                foreach ($users as $workspaceuser) {
                    if ($workspaceuser['selected']) {
                        if (!$sql_insert->execute(array(
                            ':workspaceId' => $wsId,
                            ':userId' => $workspaceuser['id']))) {
                            $myreturn = false;
                        }
                    }
                }
            }
        }
        return $myreturn;
    }

    // / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / /

    public function getItemAuthoringTools($token)
    {
        $myreturn = [];
        if ($this->isSuperAdmin($token)) {
            $sql = $this->pdoDBhandle->prepare(
                'SELECT workspaces.id as id, workspaces.name as label FROM workspaces ORDER BY workspaces.name');

            if ($sql->execute()) {

                $data = $sql->fetchAll(PDO::FETCH_ASSOC);
                if ($data != false) {
                    $myreturn = $data;
                }
            }
        }
        return $myreturn;
    }

    public function addWorkspaceGroup($token, $name): int
    {
        if ($this->isSuperAdmin($token)) {
            $sql = $this->pdoDBhandle->prepare(
                'INSERT INTO workspace_groups (name) VALUES (:wsg_name)');

            if ($sql->execute([':wsg_name' => $name])) {
                return $this->pdoDBhandle->lastInsertId();
            }
        }
        return 0;
    }
}

?>
