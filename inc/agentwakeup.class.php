<?php

/**
 * FusionInventory
 *
 * Copyright (C) 2010-2016 by the FusionInventory Development Team.
 *
 * http://www.fusioninventory.org/
 * https://github.com/fusioninventory/fusioninventory-for-glpi
 * http://forge.fusioninventory.org/
 *
 * ------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of FusionInventory project.
 *
 * FusionInventory is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * FusionInventory is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with FusionInventory. If not, see <http://www.gnu.org/licenses/>.
 *
 * ------------------------------------------------------------------------
 *
 * This file is used to manage the wake up the agents
 *
 * ------------------------------------------------------------------------
 *
 * @package   FusionInventory
 * @author    Walid Nouh
 * @author    David Durieux
 * @copyright Copyright (c) 2010-2016 FusionInventory team
 * @license   AGPL License 3.0 or (at your option) any later version
 *            http://www.gnu.org/licenses/agpl-3.0-standalone.html
 * @link      http://www.fusioninventory.org/
 * @link      https://github.com/fusioninventory/fusioninventory-for-glpi
 *
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

/**
 * Manage the wake up the agents remotely.
 */
class PluginFusioninventoryAgentWakeup extends  CommonDBTM {


   /**
    * The right name for this class
    *
    * @var string
    */
   static $rightname = 'plugin_fusioninventory_taskjob';


   /**
    * Get name of this type by language of the user connected
    *
    * @param integer $nb number of elements
    * @return string name of this type
    */
   static function getTypeName($nb = 0) {
      return __('Job', 'fusioninventory');
   }


   /**
    * Check if can wake up an agent
    *
    * @return true
    */
   static function canCreate() {
      return true;
   }


   /*
    * @function cronWakeupAgents
    * This function update already running tasks with dynamic groups
    */


   /**
    * Cron task: wake up agents. Configuration is in each tasks
    *
    * @global object $DB
    * @param object $crontask
    * @return boolean true if successfully, otherwise false
    */
   static function cronWakeupAgents($crontask) {
      global $DB;

      $wakeupArray = [];
      $tasks       = [];
      //Get the maximum number of agent to wakeup,
      //as allowed in the general configuration
      $config      = new PluginFusioninventoryConfig();
      $maxWakeUp   = $config->getValue('wakeup_agent_max');
      $counter     = 0;
      $continue    = true;

      //Get all active timeslots
      $timeslot = new PluginFusioninventoryTimeslot();
      $timeslots = $timeslot->getCurrentActiveTimeslots();
      if (empty($timeslots)) {
         $query_timeslot = '';
      } else {
         $query_timeslot = "OR (`plugin_fusioninventory_timeslots_exec_id` IN (".implode(',', $timeslots)."))";
      }
      //Get all active task requiring an agent wakeup
      //Check all tasks without timeslot or task with a current active timeslot
      $query  = "SELECT `id`, `wakeup_agent_counter`, `wakeup_agent_time`, `last_agent_wakeup`
                 FROM `glpi_plugin_fusioninventory_tasks`
                 WHERE `wakeup_agent_time` > 0
                    AND `wakeup_agent_counter` > 0
                    AND `is_active`='1'
                    AND (`plugin_fusioninventory_timeslots_exec_id` = '0'
                    $query_timeslot)";

      foreach ($DB->request($query) as $task) {
         if (!is_null($task['wakeup_agent_time'])) {
            //Do not wake up is last wake up in inferior to the minimum wake up interval
            $interval   = time() - strtotime($task['last_agent_wakeup']);
            if ($interval < ($task['wakeup_agent_time'] * MINUTE_TIMESTAMP)) {
               continue;
            }
         }

         //For each task, get a number of taskjobs at the PREPARED state
         //(the maximum is defined in wakeup_agent_counter)
         $query_states = "SELECT `taskjobstates`.`plugin_fusioninventory_agents_id`,
                                 `tasks`.`id` as `taskID`,
                                 `tasks`.`wakeup_agent_time`,
                                 `tasks`.`last_agent_wakeup`
                          FROM `glpi_plugin_fusioninventory_taskjobstates` as `taskjobstates`,
                               `glpi_plugin_fusioninventory_taskjobs` as `taskjobs`
                          LEFT JOIN `glpi_plugin_fusioninventory_tasks` as `tasks`
                             ON `tasks`.`id`=`taskjobs`.`plugin_fusioninventory_tasks_id`
                          WHERE `tasks`.`id`='".$task['id']."'
                             AND `taskjobs`.`id`=`taskjobstates`.`plugin_fusioninventory_taskjobs_id`
                             AND `taskjobstates`.`state`='".PluginFusioninventoryTaskjobstate::PREPARED."'
                          ORDER BY `taskjobstates`.`id` ASC LIMIT ".$task['wakeup_agent_counter'];
         foreach ($DB->request($query_states) as $state) {
            $agents_id = $state['plugin_fusioninventory_agents_id'];
            //Check if agent is already added to the list of agents to wake up
            if (!isset($wakeupArray[$agents_id])) {
               //This agent must be woken up
               $wakeupArray[$agents_id] = $agents_id;
               $counter++;
            }
            //Store task ID
            if (!in_array($state['taskID'], $tasks)) {
               $tasks[] = $state['taskID'];
            }

            //Do not process more than the maximum number of wakeup allowed in the configuration
            if ($counter >= $maxWakeUp) {
               if (PluginFusioninventoryConfig::isExtradebugActive()) {
                  Toolbox::logDebug(__("Maximum number of agent wakeup reached", 'fusioninventory').":".$maxWakeUp);
               }
               $continue = false;
               break;
            }
         }
         //We've reached the maximum number of agents to wake up !
         if (!$continue) {
            break;
         }
      }

      //Number of agents successfully woken up
      $wokeup = 0;
      if (!empty($tasks)) {
         //Update last wake up time each task
         $DB->update(
            'glpi_plugin_fusioninventory_tasks', [
               'last_agent_wakeup' => $_SESSION['glpi_currenttime']
            ], [
               'id' => [$tasks]
            ]
         );

         $agent  = new PluginFusioninventoryAgent();
         //Try to wake up agents one by one
         foreach (array_keys($wakeupArray) as $ID) {
            $agent->getFromDB($ID);
            if ($agent->wakeUp()) {
               $wokeup++;
            }
         }
      }

      $crontask->addVolume($wokeup);
      return true;
   }


}
