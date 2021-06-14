<?php

namespace Bitmarshals\InstantUssd\Mapper;

use Bitmarshals\InstantUssd\Mapper\TableGateway;
use Zend\Db\Adapter\Driver\ResultInterface;

/**
 * Description of UssdMenusServedMapper
 *
 * @author David Bwire
 */
class UssdMenusServedMapper extends TableGateway {

    /**
     * 
     * @param string $sessionId
     * @param string $menuId
     * @param string $phoneNumber
     * @param string $loopsetName
     * @param bool $isLoopEnd
     * @return mixed boolean|int
     */
    public function push($sessionId, $menuId, $phoneNumber, $loopsetName = null, $isLoopEnd = false) {

        $sql = $this->getSlaveSql();

        $insert = $sql->insert()
                ->values([
            'menu_id' => trim($menuId),
            'create_time' => time(),
            'session_id' => $sessionId,
            'phone_number' => $phoneNumber,
            'loopset_name' => $loopsetName,
            'is_loop_end' => ($isLoopEnd ? 1 : 0)
        ]);
        $result = $sql->prepareStatementForSqlObject($insert)
                ->execute();
        if (!$result->getAffectedRows()) {
            return false;
        }
        // return the generated id
        return (int) $result->getGeneratedValue();
    }

    /**
     * 
     * @param string $phoneNumber
     * @param int $howManyMinutesAgo
     * @return false | array false if no recent session or recent session details array
     */
    public function hasRecentSession($phoneNumber, $howManyMinutesAgo = 30) {
        $sql = $this->getSlaveSql();
        $select = $sql->select()
                ->where($this->getPredicate()->greaterThanOrEqualTo('create_time'
                                , time() - (60 * $howManyMinutesAgo)))
                ->where(['phone_number' => $phoneNumber,])
                ->order('id DESC')
                ->limit(1);
        $result = $sql->prepareStatementForSqlObject($select)->execute();
        if (!$result->count()) {
            return false;
        }
        return $result->current();
    }

    /**
     * 
     * @param string $previousSessionId
     * @param string $currentSessionId
     * @return boolean
     */
    public function swapSession($previousSessionId, $currentSessionId) {
        $sessionExists = $this->sessionExists($previousSessionId);
        if (!$sessionExists) {
            return false;
        }
        $sql = $this->getSlaveSql();
        $update = $sql->update()
                ->set(['session_id' => $currentSessionId])
                ->where(['session_id' => $previousSessionId,]);
        $result = $sql->prepareStatementForSqlObject($update)
                ->execute();
        return $this->swapLoopingSession($previousSessionId, $currentSessionId) &&
                (bool) $result->getAffectedRows();
    }

    /**
     * 
     * @param string $previousSessionId
     * @param string $currentSessionId
     * @return bool
     */
    private function swapLoopingSession($previousSessionId, $currentSessionId) {
        $sql = $this->getSlaveSql('iussd_ussd_loop');
        $update = $sql->update()
                ->set(['session_id' => $currentSessionId])
                ->where(['session_id' => $previousSessionId,]);
        $result = $sql->prepareStatementForSqlObject($update)
                ->execute();
        return (bool) $result->getAffectedRows();
    }

    /**
     * 
     * @param string $previousSessionId
     * @return bool
     */
    private function sessionExists($previousSessionId) {
        $sql = $this->getSlaveSql();
        $select = $sql->select()
                ->columns(['session_id'])
                ->where(['session_id' => $previousSessionId])
                ->limit(1);
        $result = $sql->prepareStatementForSqlObject($select)
                ->execute();
        return (bool) $result->count();
    }

    /**
     * Returns a list of menus previously visited in LIFO order
     * 
     * @param string $ussdSessionId
     * @param int $limit
     * @param array $columns
     * @return Zend\Db\Adapter\Driver\ResultInterface
     */
    public function listServedMenusBySessionId($ussdSessionId, $limit = 2, array $columns = ['id', 'menu_id']) {

        $sql = $this->getSlaveSql();
        $select = $sql->select()
                ->columns($columns)
                ->where(['session_id' => $ussdSessionId])
                ->order('create_time DESC')
                ->limit($limit);

        $results = $sql->prepareStatementForSqlObject($select)
                ->execute();

        return $results;
    }

    /**
     * 
     * @param string $ussdSessionId
     * @return boolean
     */
    public function clearMenuVisitHistoryBySessionId($ussdSessionId) {

        $sql = $this->getSlaveSql();
        $delete = $sql->delete()
                ->where(['session_id' => $ussdSessionId])
                // don't delete home_* pages
                // deleting them will lead to need for double posting
                // when a user goes back or skips to home page
                ->where($this->getPredicate()->notLike('menu_id', "home_%"));
        $result = $sql->prepareStatementForSqlObject($delete)
                ->execute();

        if ($result->getAffectedRows()) {
            return true;
        }
        return false;
    }

    /**
     * The menu that's **about to be served** is a previous position, and we're going back to it.
     * We therefore need to clear some loading history from when it was recently served to now.
     * 
     * @param string $ussdSessionId
     * @param string $menuIdToServe
     * @return boolean
     */
    public function resetMenuVisitHistoryToPreviousPosition($ussdSessionId, $menuIdToServe) {

        $sql = $this->getSlaveSql();
        // find the latest parent node
        // in sequence
        $select = $sql->select()
                ->columns(['id'])
                ->where(['session_id' => $ussdSessionId, 'menu_id' => $menuIdToServe])
                ->order('id DESC')
                ->limit(1);
        $resultSelect = $sql->prepareStatementForSqlObject($select)
                ->execute();
        $parentNodeId = null;
        if ($resultSelect->count() == 1) {
            $parentNodeId = $resultSelect->current()['id'];
        } else {
            return false;
        }
        // run a delete of all menus greater than $parentId
        $delete = $sql->delete()
                ->where(['session_id' => $ussdSessionId])
                ->where($this->getPredicate()->greaterThanOrEqualTo('id', $parentNodeId));

        $resultDelete = $sql->prepareStatementForSqlObject($delete)
                ->execute();

        if ($resultDelete->getAffectedRows()) {
            return true;
        }
        return false;
    }

    /**
     * Retrieve menu sent out on last response
     * 
     * @param string $ussdSessionId
     * @return mixed null|string
     */
    public function getLastServedMenu($ussdSessionId) {
        $sql = $this->getSlaveSql();

        $select = $sql->select()
                ->columns(['menu_id'])
                ->where(['session_id' => $ussdSessionId])
                // most recently served first
                ->order('create_time DESC')
                ->limit(1);

        $result = $sql->prepareStatementForSqlObject($select)
                ->execute();
        if (!$result->count()) {
            return null;
        }
        // extract the last result in the list
        return $result->current()['menu_id'];
    }

    /**
     * 
     * @param int $menuVisitHistoryId
     * @return boolean
     */
    public function removeMenuVisitHistoryById($menuVisitHistoryId) {
        $sql = $this->getSlaveSql();
        $delete = $sql->delete()
                ->where(['id' => $menuVisitHistoryId]);

        $result = $sql->prepareStatementForSqlObject($delete)
                ->execute();

        if ($result->getAffectedRows()) {
            return true;
        }
        return false;
    }

}
