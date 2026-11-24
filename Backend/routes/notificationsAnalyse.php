<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('comptes:check-non-affectes')->daily();
