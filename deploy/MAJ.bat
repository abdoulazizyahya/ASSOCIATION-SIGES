@echo off
rem ====================================================================
rem  MAJ.bat - zip de mise a jour SIGNE, pret pour Association > Mise a jour
rem  Glisser un ou plusieurs fichiers (ou dossiers) de SIGES sur ce fichier.
rem  Le zip est cree dans deploy\archives\ et le dossier s'ouvre.
rem ====================================================================
chcp 65001 >nul
cd /d "%~dp0.."
if "%~1"=="" (
  echo Glissez un ou plusieurs fichiers de SIGES sur MAJ.bat.
  pause
  exit /b 1
)
set "PHP=php"
where php >nul 2>nul || set "PHP=C:\wamp64\bin\php\php8.3.14\php.exe"
"%PHP%" deploy\outils\construire_zip.php --fichiers auto %*
if errorlevel 1 (
  echo.
  echo Echec de la creation du zip.
  pause
  exit /b 1
)
echo.
echo Envoyez ce zip depuis le portail : Association ^> Mise a jour.
start "" explorer "%CD%\deploy\archives"
pause
