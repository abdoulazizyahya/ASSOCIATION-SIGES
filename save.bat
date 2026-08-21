@echo off
REM Script pour mettre à jour le dépôt GitHub jaynitaare_v2

cd C:\wamp64\www\jaynitaare_v2

REM Vérifier si le dossier est déjà un dépôt Git
IF NOT EXIST ".git" (
    echo Initialisation du dépôt Git...
    git init
    git remote add origin https://github.com/abdoulazizyahya/jaynitaare_v2.git
)

REM Ajouter tous les fichiers
git add .

REM Créer un commit avec horodatage
set DATE=%date% %time%
git commit -m "Mise à jour automatique %DATE%"

REM Pousser vers GitHub (branche principale)
git push origin main

echo Mise à jour terminée !
pause
