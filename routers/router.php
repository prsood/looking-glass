<?php

/*
 * Looking Glass - An easy to deploy Looking Glass
 * Copyright (C) 2014-2019 Guillaume Mazoyer <gmazoyer@gravitons.in>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software Foundation,
 * Inc., 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301  USA
 */

require_once('includes/config.defaults.php');
require_once('config.php');
require_once('bird.php');
require_once('cisco.php');
require_once('cisco_iosxr.php');
require_once('extreme_netiron.php');
require_once('juniper.php');
require_once('mikrotik.php');
require_once('openbgpd.php');
require_once('quagga.php');
require_once('frr.php');
require_once('vyatta.php');
require_once('includes/utils.php');
require_once('auth/authentication.php');

abstract class Router {
  protected $global_config;
  protected $config;
  protected $id;
  protected $requester;

  public function __construct($global_config, $config, $id, $requester) {
    $this->global_config = $global_config;
    $this->config = $config;
    $this->id = $id;
    $this->requester = $requester;

    // Set defaults if not present
    if (!isset($this->config['timeout'])) {
      $this->config['timeout'] = 30;
    }
    if (!isset($this->config['disable_ipv6'])) {
      $this->config['disable_ipv6'] = false;
    }
    if (!isset($this->config['disable_ipv4'])) {
      $this->config['disable_ipv4'] = false;
    }
    if (!isset($this->config['bgp_detail'])) {
      $this->config['bgp_detail'] = false;
    }
  }

  private function sanitize_output($output) {
    // No filters defined
    if (count($this->global_config['filters']['output']) < 1) {
      return preg_replace('/(?:\n|\r\n|\r)$/D', '', $output);
    }

    $filtered = '';

    foreach (preg_split("/((\r?\n)|(\r\n?))/", $output) as $line) {
      $valid = true;

      foreach ($this->global_config['filters']['output'] as $filter) {
        // Line has been marked as invalid
        // Or filtered based on the configuration
        if (!$valid || (preg_match($filter, $line) === 1)) {
          $valid = false;
          break;
        }
      }

     if ($valid) {
        // The line is valid, print it
        $filtered .= $line."\n";
      }
    }

    return preg_replace('/(?:\n|\r\n|\r)$/D', '', $filtered);
  }

  protected function format_output($command, $output) {
    if ($this->global_config['output']['show_command']) {
      $displayable = '<p><kbd>Command: '.$command.'</kdb></p>';
    }
    $displayable .= '<pre class="pre-scrollable">'.$output.'</pre>';

    return $displayable;
  }

  protected function has_source_interface_id() {
    return isset($this->config['source-interface-id']);
  }

  protected function get_source_interface_id($ip_version = 'ipv6') {
    // No source interface ID specified
    if (!$this->has_source_interface_id()) {
      return null;
    }

    $source_interface_id = $this->config['source-interface-id'];

    if (!is_array($source_interface_id)) {
      // Interface not being IP version specific
      return $source_interface_id;
    }
    return $source_interface_id[$ip_version];
  }

  protected abstract function build_bgp($parameter);

  protected abstract function build_aspath_regexp($parameter);

  protected abstract function build_as($parameter);

  protected abstract function build_ping($parameter);

  protected abstract function build_traceroute($parameter);

  private function build_commands($command, $parameter) {
    $commands = array();

    switch ($command) {
      case 'bgp':
        if (!is_valid_ip_address($parameter)) {
          throw new Exception('The parameter is not an IP address.');
        }
        array_push($commands, $this->build_bgp($parameter));
        break;

      case 'as-path-regex':
        if (!match_aspath_regexp($parameter)) {
          throw new Exception('The parameter is not an AS-Path regular expression.');
        }
        array_push($commands, $this->build_aspath_regexp($parameter));
        break;

      case 'as':
        if (!match_as($parameter)) {
          throw new Exception('The parameter is not an AS number.');
        }
        array_push($commands, $this->build_as($parameter));
        break;

      case 'ping':
        array_push($commands, $this->build_ping($parameter));
        break;

      case 'traceroute':
        array_push($commands, $this->build_traceroute($parameter));
        break;

      default:
        throw new Exception('Command not supported.');
    }

    return $commands;
  }

  public function get_config() {
    return $this->config;
  }

  public function send_command($command, $parameter) {
    $commands = $this->build_commands($command, $parameter);
    $auth = Authentication::instance($this->config,
      $this->global_config['logs']['auth_debug']);

    $data = '';

    foreach ($commands as $selected) {
      $log = str_replace(array('%D', '%R', '%H', '%C'),
        array(date('Y-m-d H:i:s'), $this->requester, $this->config['host'],
        '[BEGIN] '.$selected), $this->global_config['logs']['format']);
      log_to_file($log);

      $output = $auth->send_command((string) $selected);
      $output = $this->sanitize_output($output);

      $data .= $this->format_output($selected, $output);

      $log = str_replace(array('%D', '%R', '%H', '%C'),
        array(date('Y-m-d H:i:s'), $this->requester, $this->config['host'],
        '[END] '.$selected), $this->global_config['logs']['format']);
      log_to_file($log);
    }

    return $data;
  }

  public static final function instance($id, $requester) {
    global $config;

    $router_config = $config['routers'][$id];

    switch (strtolower($router_config['type'])) {
      case 'bird':
        return new Bird($config, $router_config, $id, $requester);

      case 'cisco':
      case 'ios':
        return new Cisco($config, $router_config, $id, $requester);

      case 'extreme_netiron':
        return new ExtremeNetIron($config, $router_config, $id, $requester);

      case 'ios-xr':
      case 'iosxr':
        return new IOSXR($config, $router_config, $id, $requester);

      case 'juniper':
      case 'junos':
        return new Juniper($config, $router_config, $id, $requester);

      case 'mikrotik':
      case 'routeros':
        return new Mikrotik($config, $router_config, $id, $requester);

      case 'openbgpd':
        return new OpenBGPD($config, $router_config, $id, $requester);

      case 'quagga':
      case 'zebra':
        return new Quagga($config, $router_config, $id, $requester);

      case 'frr':
        return new FRR($config, $router_config, $id, $requester);

      case 'vyatta':
      case 'vyos':
      case 'edgeos':
        return new Vyatta($config, $router_config, $id, $requester);

      default:
        print('Unknown router type "'.$router_config['type'].'".');
        return null;
    }
  }
}

// End of router.php
