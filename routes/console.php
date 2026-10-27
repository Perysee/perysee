<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('perysee:aggregate --batch=500 --budget-seconds=15')->everyFiveMinutes();
Schedule::command('perysee:maintenance --limit=1000')->everyFiveMinutes();

Schedule::command('perysee:rebuild-segments --batch=500')->everyFiveMinutes();

Schedule::command('perysee:journey-reports --batch=500 --budget=10')->everyMinute()->withoutOverlapping();
