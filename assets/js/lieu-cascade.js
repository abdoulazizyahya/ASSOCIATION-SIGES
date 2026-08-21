/**
 * assets/js/lieu-cascade.js — Widget région → département → arrondissement
 * en cascade (AJAX), avec repli « Autre (non répertorié, préciser) » vers
 * un champ texte libre. Réutilisable sur toute fiche ayant un lieu à
 * capturer (naissance, origine, résidence…) : élève, enseignant, tuteur…
 *
 * Chaque select est peuplé par id (code_depart / code_arrond), jamais par
 * texte — évite toute dépendance fragile au libellé affiché.
 *
 * Contrat de soumission (cf. spec du 10/08/2026) : le select arrondissement
 * porte le code_arrond comme value ; sa dernière option (value="__autre__")
 * affiche à la place le champ texte libre. Le nom du select doit être
 * "id_arrondissement" — si sa valeur est "__autre__" ou vide, le formulaire
 * doit utiliser le champ texte libre (name="lieu_libre" par exemple) côté
 * serveur : SEULE cette paire de champs doit être lue, jamais 3 colonnes
 * région/département/arrondissement texte séparées.
 *
 * Utilisation :
 *   initLieuCascade({
 *     selRegion:         document.getElementById('sel_region'),
 *     selDepartement:    document.getElementById('sel_departement'),
 *     selArrondissement: document.getElementById('sel_arrondissement'),
 *     inputLibre:        document.getElementById('inp_lieu_libre'),
 *     appUrl:            '/jaynitaare_v2',
 *     valeurs: {                        // optionnel — pré-remplissage (édition)
 *       id_region:         2,
 *       id_departement:    7,
 *       id_arrondissement: null,        // connu si la fiche est déjà liée
 *       texte_libre:       'Ancien lieu saisi en texte libre'
 *     }
 *   });
 *
 * Le select Région lui-même n'est PAS peuplé par ce widget : les régions
 * sont une petite liste fixe (10), rendue directement en PHP au chargement
 * de la page (pas besoin d'AJAX pour un premier niveau statique) — ce
 * widget ne gère que les 2 niveaux qui dépendent réellement d'une sélection
 * précédente.
 */
function initLieuCascade(opts) {
    const selRegion         = opts.selRegion;
    const selDepartement    = opts.selDepartement;
    const selArrondissement = opts.selArrondissement;
    const inputLibre        = opts.inputLibre;
    const appUrl            = opts.appUrl || '';
    const valeurs           = opts.valeurs || {};

    function peuplerOptions(sel, items, texteVide) {
        sel.innerHTML = '';
        const optVide = document.createElement('option');
        optVide.value = '';
        optVide.textContent = texteVide;
        sel.appendChild(optVide);
        items.forEach(function (it) {
            const o = document.createElement('option');
            o.value = String(it.id);
            o.textContent = it.nom;
            sel.appendChild(o);
        });
        return optVide;
    }

    function reinitArrondissement(texteVide) {
        selArrondissement.innerHTML = '';
        const opt = document.createElement('option');
        opt.value = '';
        opt.textContent = texteVide;
        selArrondissement.appendChild(opt);
        selArrondissement.disabled = true;
        gererChampLibre();
    }

    function gererChampLibre() {
        const estAutre = selArrondissement.value === '__autre__';
        inputLibre.style.display = estAutre ? '' : 'none';
        inputLibre.required = estAutre;
        if (!estAutre) inputLibre.value = '';
    }

    function chargerDepartements(idRegion, preselectionDept, preselectionArr) {
        reinitArrondissement('— Choisir un département d\'abord —');
        if (!idRegion) {
            selDepartement.innerHTML = '<option value="">— Choisir une région d\'abord —</option>';
            selDepartement.disabled = true;
            return;
        }
        selDepartement.innerHTML = '<option value="">Chargement…</option>';
        selDepartement.disabled = true;
        fetch(appUrl + '/ajax/departements_par_region.php?id_region=' + encodeURIComponent(idRegion))
            .then(function (r) { return r.json(); })
            .then(function (deps) {
                selDepartement.disabled = false;
                peuplerOptions(selDepartement, deps, '— Choisir un département —');
                if (preselectionDept) {
                    selDepartement.value = String(preselectionDept);
                    chargerArrondissements(preselectionDept, preselectionArr);
                }
            })
            .catch(function () {
                selDepartement.innerHTML = '<option value="">— Erreur de chargement —</option>';
            });
    }

    function chargerArrondissements(idDepartement, preselectionArr) {
        if (!idDepartement) {
            reinitArrondissement('— Choisir un département d\'abord —');
            return;
        }
        selArrondissement.innerHTML = '<option value="">Chargement…</option>';
        selArrondissement.disabled = true;
        fetch(appUrl + '/ajax/arrondissements_par_departement.php?id_departement=' + encodeURIComponent(idDepartement))
            .then(function (r) { return r.json(); })
            .then(function (arrs) {
                selArrondissement.disabled = false;
                const optVide = peuplerOptions(
                    selArrondissement, arrs,
                    arrs.length ? '— Choisir un arrondissement —' : '— Aucun arrondissement enregistré pour ce département —'
                );
                const optAutre = document.createElement('option');
                optAutre.value = '__autre__';
                optAutre.textContent = 'Autre (non répertorié, préciser)';
                selArrondissement.appendChild(optAutre);

                if (preselectionArr) {
                    selArrondissement.value = String(preselectionArr);
                } else if (valeurs.texte_libre) {
                    // Fiche existante avec un lieu en texte libre (pas encore lié à un
                    // id officiel) : bascule sur "Autre" pour proposer directement le
                    // champ texte pré-rempli, plutôt que de perdre la saisie historique.
                    optAutre.selected = true;
                } else if (!arrs.length) {
                    optAutre.selected = true;
                }
                gererChampLibre();
            })
            .catch(function () {
                selArrondissement.innerHTML = '<option value="">— Erreur de chargement —</option>';
            });
    }

    selRegion.addEventListener('change', function () {
        chargerDepartements(this.value, null, null);
    });
    selDepartement.addEventListener('change', function () {
        chargerArrondissements(this.value, null);
    });
    selArrondissement.addEventListener('change', gererChampLibre);

    // Initialisation (pré-remplissage en mode édition)
    if (valeurs.texte_libre && !valeurs.id_arrondissement) {
        inputLibre.value = valeurs.texte_libre;
    }
    if (valeurs.id_region) {
        selRegion.value = String(valeurs.id_region);
        chargerDepartements(valeurs.id_region, valeurs.id_departement || null, valeurs.id_arrondissement || null);
    } else {
        reinitArrondissement('— Choisir une région, puis un département —');
    }
}
