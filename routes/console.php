<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('perysee:prune-tracking --limit=1000')->everyFiveMinutes()->withoutOverlapping(10);
