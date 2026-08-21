@echo off
REM Script de synchronisation GitHub pour jaynitaare_v2

cd C:\wamp64\www\jaynitaare_v2

REM Vérifier si le dossier est déjà un dépôt Git
IF NOT EXIST ".git" (
    echo Initialisation du dépôt Git...
    git init
    git remote add origin https://github.com/abdoulazizyahya/jaynitaare_v2.git
)

echo ============================================
echo 1 - Mettre à jour depuis GitHub (pull)
echo 2 - Envoyer vers GitHub (push)
echo ============================================
set /p choix="Votre choix (1 ou 2): "

IF "%choix%"=="1" (
    echo Récupération des mises à jour depuis GitHub...
    git pull origin main
    echo Pull terminé !
    pause
    exit
)

IF "%choix%"=="2" (
    echo Préparation de l'envoi vers GitHub...
    git add .
    set DATE=%date% %time%
    git commit -m "Mise à jour automatique %DATE%"
    git push origin main
    echo Push terminé !
    pause
    exit
)

echo Choix invalide.
pause
