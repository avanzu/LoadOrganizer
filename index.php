<?php
include 'LoadOrganizer.php';

$json      = json_decode(file_get_contents('config.json'));
// $organizer = new Organizer($json);

$organizer = new LoadOrganizer();
foreach($json->scripts as $src => $attr) {
    $organizer->register($src, $attr->provides, $attr->requires);
}
$organizer
    ->queue('jquery')
    ->queue("somefeature")
    ->queue('jquery-ui')
    ->queue('bootstrap')
    ->queue('backbone')
    ->queue('bootstrap')
    ->queue('fancytree')
    ->queue('datatables')
;
foreach($organizer->resolve() as $key => $value) {
    echo "$key: " . (string)$value . PHP_EOL;
}