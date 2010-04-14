<?php

/**
 * Abstract class for union and intersection of sorted sets 
 * 
 * @author Ivan Shumkov
 * @package Rediska
 * @version @package_version@
 * @link http://rediska.geometria-lab.net
 * @licence http://www.opensource.org/licenses/bsd-license.php
 */
abstract class Rediska_Command_CompareSortedSets extends Rediska_Command_Abstract
{
	const SUM = 'sum';
    const MAX = 'max';
    const MIN = 'min';

    protected $_version = '1.3.5';
    
    abstract protected $_command;
	
	protected $_storeConnection;
	protected $_names   = array();
	protected $_weights = array();

    protected function _create(array $names, $storeName, $aggregation = self::SUM)
    {
        if (!empty($names)) {
            throw new Rediska_Command_Exception('You must specify sorted sets');
        }

        // With weights?
        $withWeights = false;
        foreach($names as $nameOrIndex => $weightOrName) {
            if (is_string($nameOrIndex)) {
                $names = array_keys($names);
                $this->_weights = $names;
                $withWeights = true;
                break;
            }
        }

        $connections = array();
        $namesByConnections = array();
        foreach ($names as $name) {
            $connection = $this->_rediska->getConnectionByKeyName($name);
            $connectionAlias = $connection->getAlias();
            if (!array_key_exists($connectionAlias, $connections)) {
                $connections[$connectionAlias] = $connection;
                $namesByConnections[$connectionAlias] = array();
            }
            $namesByConnections[$connectionAlias][] = $name;
        }

        // If only one connection, compare by redis
        if (count($connections) == 1) {
            $connectionValues = array_values($connections);
            $connection = $connectionValues[0];
            $storeConnection = $this->_rediska->getConnectionByKeyName($storeName);

            if ($storeConnection->getAlias() == $connection->getAlias()) {
                $command = array($this->_command, count($names)) + $names;
    
                if ($withWeights) {
                    $command[] = 'WEIGHTS';
                    $command += array_values($this->_weights);
                }

                if (strtolower($aggregation) != self::SUM) {
                    $command[] = 'AGGREGATE';
                    $command[] = strtoupper($aggregation);
                }

                return $this->_addCommandByConnection($connection, $command);
            }
        }

        // Compare by hand
        
        // Set default weights
        if (!$withWeights) {
            $this->_weights = array_fill_keys($names, 1);
        }

        $this->setAtomic(false);
        foreach($namesByConnections as $connectionAlias => $keys) {
            foreach($keys as $key) {
                $this->_names[] = $key;
                $command = array("ZRANGE", "{$this->_rediska->getOption('namespace')}$key", 0, -1, 'WITHSCORES');
                $this->_addCommandByConnection($connections[$connectionAlias], $command);
            }
        }
    }
    
    abstract function _compareSets($sets);

    protected function _parseResponses($responses)
    {
        if ($this->isAtomic()) {
    	   return $responses[0];
        } else {
            $values = array();
            $valuesWithScores = array();
            foreach ($this->_names as $name) {
                $values[$name] = array();
                $response = next($responses);
                foreach ($response as $valuesAndScores) {
                    $isValue = true;
                    foreach ($valuesAndScores as $valueOrScore) {
                        if ($isValue) {
                            $value = $valueOrScore;
                            $values[$name][] = $value;
                        } else {
                            $score = $valueOrScore;
                            if (isset($valuesWithScores[$value])) {
                                $valuesWithScores[$value] = array();
                            }
                            $valuesWithScores[$value][] = $score * $this->_weights[$name];
                        }
                        $isValue = !$isValue;
                    }
                }
            }

            $aggregation = strtolower($this->aggregation);
            
            $pipeline = $this->_rediska->pipeline();

            $count = 0;
            foreach($this->_compareSets($values) as $value) {
                $scores = $valuesWithScores[$value];
                switch ($aggregation) {
                    case self::SUM:
                        $score = array_sum($scores);
                        break;
                    case self::MIN:
                        $score = min($scores);
                        break;
                    case self::MAX:
                        $score = max($scores);
                        break;
                    default:
                        throw new Rediska_Command_Exception('Unknown aggregation method ' . $this->aggregation);
                }

                $value = $this->_rediska->unserialize($value);

                $pipeline->addToSortedSet($this->storeName, $value, $score);

                $count++;
            }

            $pipeline->execute();

            return $count;
        }
    }
}