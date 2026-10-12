@echo off
cd C:\wamp64\www\jaynitaare_v2

IF NOT EXIST ".git" (
    echo Initialisation du dépôt Git...
    git init
)

REM Configurer le remote origin si absent
git remote remove origin >nul 2>&1
git remote add origin https://github.com/abdoulazizyahya/jaynitaare_v2.git

REM Vérifier si un commit existe
git rev-parse --verify HEAD >nul 2>&1
IF ERRORLEVEL 1 (
    echo Aucun commit trouvé, création du commit initial...
    git add .
    git commit -m "Initial commit"
)

REM Renommer la branche en main
git branch -M main

echo ============================================
echo 1 - Mettre à jour depuis GitHub (pull)
echo 2 - Envoyer vers GitHub (push)
echo ============================================
set /p choix="Votre choix (1 ou 2): "

IF "%choix%"=="1" (
    git pull origin main
    echo Pull terminé !
    pause
    exit
)

IF "%choix%"=="2" (
    git add .
    set DATE=%date% %time%
    git commit -m "Mise à jour automatique %DATE%"
    git push -u origin main
    echo Push terminé !
    pause
    exit
)

echo Choix invalide.
pause
