@echo off
title REGIS+ - Registrar Records Management System
cd /d "%~dp0"

if not exist "runtime\php\php.exe" (
    echo.
    echo   PHP runtime not found in .\runtime\php
    echo   Re-download the portable PHP build into that folder.
    echo.
    pause
    exit /b 1
)

if not exist "database\regis.sqlite" (
    echo   First run: creating the database...
)

echo.
echo   REGIS+ is starting...
echo   Address:  http://localhost:8000
echo   Login:    etalha / regis2026
echo   Stop:     press Ctrl+C
echo.

start "" powershell -NoProfile -WindowStyle Hidden -Command "Start-Sleep -Seconds 2; Start-Process 'http://localhost:8000'"
runtime\php\php.exe -S localhost:8000 router.php
pause
