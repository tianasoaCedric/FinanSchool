<?php

// require_once : notifications.php déclare une classe ; un second chargement
// (tests qui redémarrent l'application) provoquait « Cannot declare class ».
require_once __DIR__ . '/notificationsAnalyse.php';
require_once __DIR__ . '/notifications.php';

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('exercice:cloture-alertes')->everyMinute();
