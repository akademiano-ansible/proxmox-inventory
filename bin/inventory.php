#!/usr/bin/env php
<?php
declare(strict_types=1);

define("ROOT_PATH", realpath(__DIR__ . DIRECTORY_SEPARATOR . ".."));

require_once ROOT_PATH . "/vendor/autoload.php";

use ProxmoxVE\Proxmox;

function getOptions(): array
{
    $shortopts = "";

    $longopts = [
        "host:",
        "list",
    ];

    $options = getopt($shortopts, $longopts);
    return $options;
}

function getProxmox(array $credentials): Proxmox
{
    static $proxmox = null;

    if (null === $proxmox) {
        $proxmox = new Proxmox($credentials);
    }
    return $proxmox;
}

function getCredentials(): array
{
    static $credentials = null;

    if (null === $credentials) {
        $credentials = include ROOT_PATH . "/config/credentials.php";
    }
    return $credentials;
}

function getParams(): array
{
    static $params = null;

    if (null === $params) {
        $params = include ROOT_PATH . "/config/params.php";
    }
    return $params;
}

function getNodePath(array $params): ?string
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

function getVmsList(Proxmox $proxmox): ?array
{
    $nodePath = getNodePath(getParams());
    $vms = $proxmox->get(sprintf("%s/qemu", $nodePath));
    //work with only running and not templates
    $vms = array_filter($vms["data"], function ($item) {
        return ($item["template"] !== 1);
    });
    /*$vmids = array_map(function (array $item) {
        return (int)$item["vmid"];
    }, $vms);*/
    #var_dump($vmids);
    $vmslist = [];
    foreach ($vms as $vm) {
        $vmslist[] = getVmInfo($proxmox, (int)$vm["vmid"]);
    }

    $groups = [];
    $meta = [];
    foreach ($vmslist as $vm) {
        $vmGroups = $vm["groups"];
        foreach ($vmGroups as $group) {
            $groups[$group]["hosts"][] = $vm["name"];
        }
        $meta["hostvars"][$vm["name"]] = [];
        if (isset($vm["ip"])) {
            $meta["hostvars"][$vm["name"]] = [
                "ansible_host" => $vm["ip"],
            ];
        }
    }

    $meta["hostvars"] = array_map(function ($hostData) {
        return !empty($hostData) ? $hostData : new \stdClass();
    }, $meta["hostvars"]);


//    var_dump($groups, $meta);
    return [
        "meta" => $meta,
        "groups" => $groups,
    ];
}

function getVmInfo(Proxmox $proxmox, int $vmid): ?array
{
//    $vmid = 701;
    $data = [
        "vmid" => $vmid,
    ];


    $nodePath = getNodePath(getParams());
    $vmApiPrefix = sprintf("%s/qemu/%d", $nodePath, $vmid);

    $vmStatus = $proxmox->get(sprintf("%s/status/current", $vmApiPrefix));
    $vmStatus = $vmStatus["data"];
    $vmName = $vmStatus["name"];
    $data["name"] = $vmName;
    $isRun = $vmStatus["status"] === "running";

    $data["status"] = $vmStatus["status"];

    $vmInfo = $proxmox->get(sprintf("%s/config", $vmApiPrefix));
    $vmInfo = $vmInfo["data"];

    $desc = $vmInfo["description"] ?? "";

    $descArray = json_decode($desc, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        $descArray = [];
    }

    $groups = $descArray["groups"] ?? [];
    $groups[] = "ProxmoxGuest";
    $groups[] = ucfirst($vmStatus["status"]);

    if ($isRun) {
        //get agent info
        $isAgent = (bool)($vmInfo["agent"] ?? 0);
        if ($isAgent) {
            $agentOsInfo = $proxmox->get(sprintf("%s/agent/get-osinfo", $vmApiPrefix));
            $agentOsInfo = $agentOsInfo["data"] ?? [];
            $agentOsInfo = $agentOsInfo["result"] ?? [];
            $os = $agentOsInfo["name"] ?? null;
            if ($os) {
                $groups[] = $os;
            }

            $agentNetInfo = $proxmox->get(sprintf("%s/agent/network-get-interfaces", $vmApiPrefix));
            $agentNetInfo = $agentNetInfo["data"] ?? [];
            $agentNetInfo = $agentNetInfo["result"] ?? [];
            $agentNetInfo = array_filter($agentNetInfo, function ($item) {
                return ($item["name"] !== "lo" and isset($item["ip-addresses"]));
            });
            $ips = [];
            foreach ($agentNetInfo as $ifData) {
                foreach ($ifData["ip-addresses"] ?? [] as $ipAddrData) {
                    if ($ipAddrData["ip-address-type"] === "ipv4") {
                        $ips[] = $ipAddrData["ip-address"];
                    }
                }
            }
            $ip = array_shift($ips);
            $data["ip"] = $ip;
        }
    }
    $data["groups"] = $groups;
    return $data;
}


function getVmInfoByName(Proxmox $proxmox, string $host): ?array
{

}

function printList(array $info): bool
{
    $result = ["_meta" => $info["meta"]] + $info["groups"];
    print json_encode($result, JSON_PRETTY_PRINT);

    return true;
}

function main(): ?bool
{
    $options = getOptions();

    if (empty($options)) {
        throw new \Exception("Error: empty options");
        return false;
    }
    $option_names = array_keys($options);
    $option = array_shift($option_names);
    $proxmox = getProxmox(getCredentials());

    switch ($option) {
        case "list":
            $result = getVmsList($proxmox);
            if (!$result) {
                error_log("Null result");
                return false;
            }
            printList($result);
            return true;
            break;
        case "host":
            die(5);
            break;
    }
}

$result = main();
if (!$result) {
    exit(1);
}
