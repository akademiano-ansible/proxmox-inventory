#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once '../vendor/autoload.php';

use ProxmoxVE\Proxmox;

function getOptions():array
{
  $shortopts  = "";

  $longopts  = [
    "host:",
    "list",
  ];
  
  $options = getopt($shortopts, $longopts);
  return $options;
}

function getProxmox(array $credentials):Proxmox
{
  static $proxmox = null;

  if (null === $proxmox) {
    $proxmox = new Proxmox($credentials);
  }
  return $proxmox;
}

function getCredentials():array
{
  static $credentials = null;
  
  if (null === $credentials) {
    $credentials = include "../config/credentials.php";
  }
  return $credentials;
}

function getParams():array
{
  static $params = null;
  
  if (null === $params) {
    $params = include "../config/params.php";
  }
  return $params;
}

function getNodePath(array $params):?string
{
  static $nodePath = null;
  if (null === $nodePath) {
    if (!array_key_exists("node", $params)) {
      throw new \Exception("Error:not defined node in params");
      return null;
    }
    $node = $params["node"];
    $nodePath = sprintf("/nodes/%s", $node);
  }
  return $nodePath;
}

function getVmsList(Proxmox $proxmox):?array
{
  $nodePath = getNodePath(getParams());
  $vms = $proxmox->get(sprintf("%s/qemu", $nodePath));
  $vms = array_filter($vms["data"], function ($item) {return $item["template"]!==1;});
  $vmids = array_map(function (array $item) {return (int)$item["vmid"];}, $vms);
  #var_dump($vmids);
  $vmslist = [];
  foreach ($vmids as $vmid) {
    $vmslist[] = getVmInfo($proxmox, $vmid);
  }
  var_dump($vmslist);
  return $vmslist;
}

function getVmInfo(Proxmox $proxmox, int $vmid):?array
{
  $nodePath = getNodePath(getParams());
  $vm = $proxmox->get(sprintf("%s/qemu/%d/config", $nodePath, $vmid));
  return $vm;
}


function getVmInfoByName(Proxmox $proxmox, string $host):?array
{

}

function printInfo(array $info):bool
{
#  print json_encode($info, JSON_PRETTY_PRINT);
  return true;
}

function main():?bool
{
  $options = getOptions();

  if (empty($options)){
    throw new \Exception("Error: empty options");
    return false;
  }
  $option_names = array_keys($options);
  $option = array_shift($option_names);
  $proxmox = getProxmox(getCredentials());

  switch ($option) {
    case "list":
      $result = getVmsList($proxmox);
      break;
    case "host":
      $result = getVmInfoByName($proxmox, $options["host"]);
      break;
  }

  if (!$result) {
    error_log("Null result");
    return false;
  }
  
  printInfo($result);
  return true;
}

$result = main();
if (!$result) {
 exit(1);
}
