<?php

// Update Rediska method tags:
// @method int borp() borp(int $int1, int $int2) multiply two integers

define('REDISKA', __DIR__ . '/../library/Rediska.php');


function getReturn($classReflection, $method)
{
    $createReflection = $classReflection->getMethod($method);
    $methodDocBlock = $createReflection->getDocComment();
    preg_match('/@return\s+(\S+)/i', $methodDocBlock, $matches);

    return $matches[1];
}

require_once REDISKA;
$classReflection = new ReflectionClass('Rediska');
$dockBlock = $classReflection->getDocComment();
$methodsStart = strpos($dockBlock, '@method');
if ($methodsStart !== false) {
    $newDockBlock = substr($dockBlock, 0, $methodsStart - 4);
} else {
    $newDockBlock = substr($dockBlock, 0, -1);
}

$newDockBlock .= "\n";

$count = 0;
foreach(Rediska::getCommands() as $name => $class) {
    // Get name
    $name = lcfirst(substr($class, 16));

    // Get description
    $classReflection = new ReflectionClass($class);
    $docBlockLines = explode("\n", $classReflection->getDocComment());
    $description = trim(substr($docBlockLines[1], 2));

    // Get params
    $createReflection = $classReflection->getMethod('create');
    $methodDocBlock = $createReflection->getDocComment();
    preg_match_all('/@param\s+(\S+)\s+(\S+)\s+(.*)/i', $methodDocBlock, $matches, PREG_SET_ORDER);
    $params = '';
    if (!empty($matches)) {
        foreach($matches as $match) {
            $type = $match[1];
            $var  = str_replace('[optional]', '', $match[2]);

            $params .= ", $type $var";
        }
        $params = substr($params, 2);
    }

    // Get return
    $return = getReturn($classReflection, 'parseResponse');
    if ($return == 'mixed') {
        getReturn($classReflection, 'parseResponses');
    }

    $newDockBlock .= " * @method $return $name() $name($params) $description\n";

	$count++;
}

$newDockBlock .= ' */';

$rediska = file_get_contents(REDISKA);
$newRediska = str_replace($dockBlock, $newDockBlock, $rediska);
file_put_contents(REDISKA, $newRediska);

print "$count tags added.\n";