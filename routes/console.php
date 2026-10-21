<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('invoices:issue-recurring')->dailyAt('07:00')->timezone('Europe/London');
Schedule::command('invoices:send-reminders')->dailyAt('09:00')->timezone('Europe/London');
