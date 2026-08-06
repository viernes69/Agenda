@echo off
REM Process notification outbox — configure via Windows Task Scheduler
REM Task: Run every 2 minutes
REM Action: Start a program → browse to this .bat file
REM
REM Scheduler setup:
REM   1. Open Task Scheduler (taskschd.msc)
REM   2. Create Basic Task → Name: "Process Notification Outbox"
REM   3. Trigger: Daily, then modify to repeat every 2 minutes for 1 day
REM   4. Action: Start a Program → C:\xampp\htdocs\Agenda\bin\process-outbox-cron.bat
REM   5. Conditions: Uncheck "Start only if on AC power"
REM   6. Settings: Uncheck "Stop task if it runs longer than"

cd /d "%~dp0.."
set LOGDIR=%CD%\storage\logs
if not exist "%LOGDIR%" mkdir "%LOGDIR%"

echo [%date% %time%] Running process-outbox... >> "%LOGDIR%\outbox.log"
php "%~dp0process-outbox.php" >> "%LOGDIR%\outbox.log" 2>&1
if errorlevel 1 (
  echo [%date% %time%] ERROR: process-outbox failed with exit code %errorlevel% >> "%LOGDIR%\outbox.log"
)
