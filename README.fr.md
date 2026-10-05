# WP-CLI DB Prefix

[English](README.md)

Un outil gratuit pour changer, sans rien casser, le « préfixe » des tables d'un site WordPress.

## C'est quoi ?

Un site WordPress range tout son contenu (pages, articles, comptes, réglages) dans une base de données. Cette base est découpée en tiroirs, appelés « tables ». Le nom de chaque tiroir commence par la même étiquette : `wp_`, sauf si quelqu'un l'a changée à l'installation.

Comme cette étiquette est la même sur des millions de sites, les robots qui attaquent les sites web la connaissent. En la remplaçant par une étiquette propre à votre site, par exemple `k7qm_`, on leur complique un peu la tâche. C'est une petite mesure de sécurité, à ajouter aux autres (mises à jour, mots de passe solides, sauvegardes), pas une protection à elle seule.

Le faire à la main est délicat : il faut renommer chaque tiroir, corriger plusieurs réglages cachés et modifier le fichier de configuration du site. Le moindre oubli et le site ne s'affiche plus. Cet outil fait tout en une seule commande. Il vérifie tout avant de commencer, et si quelque chose se passe mal, il remet le site exactement comme avant.

## Qui est derrière ?

[Thierry Pigot](https://www.wearewp.pro), président de WeAre[WP], une agence française spécialisée dans WordPress. L'outil a été créé pour la maintenance des sites de nos clients, puis publié pour que tout le monde puisse s'en servir. Il est libre et gratuit (licence GPL).

## Pour qui ?

L'outil s'utilise avec **WP-CLI**, le « terminal » de WordPress : on tape des commandes au lieu de cliquer dans l'administration. C'est l'outil des personnes qui s'occupent techniquement d'un site (développeur, agence, hébergeur).

Si ce n'est pas votre cas, transmettez simplement cette page à la personne qui gère votre site.

## Installation

Une seule commande, sur le serveur du site :

```bash
wp package install thierrypigot/wp-cli-db-prefix
```

## Comment ça marche

**1. Regarder ce qui va se passer, sans rien toucher :**

```bash
wp db-prefix rename --dry-run
```

L'outil choisit une nouvelle étiquette au hasard (par exemple `k7qm_`), liste tout ce qu'il ferait, et s'arrête là. Rien n'est modifié.

**2. Faire le changement, avec une sauvegarde avant :**

```bash
wp db-prefix rename --safe
```

L'outil sauvegarde le site, demande confirmation, change l'étiquette, puis affiche les commandes pour revenir en arrière si besoin. Pour choisir vous-même l'étiquette, ajoutez-la : `wp db-prefix rename k7qm_ --safe`.

**3. Vérifier le site**, puis supprimer la sauvegarde : elle contient les mots de passe de la base de données.

## Ce que l'outil fait pour protéger votre site

- **Il vérifie tout avant de commencer.** Au moindre doute, il s'arrête sans avoir rien touché et explique pourquoi.
- **Il sauvegarde d'abord**, avec `--safe`, dans un dossier que les visiteurs du site ne peuvent pas atteindre.
- **Il met le site en maintenance** pendant l'opération, qui dure quelques secondes : les visiteurs voient un message d'attente plutôt qu'une erreur.
- **Il change tous les tiroirs d'un coup** : soit tout est renommé, soit rien.
- **Il modifie le fichier de configuration en dernier**, une fois tout le reste réussi.
- **Il annule tout si une étape échoue**, et le dit clairement.
- **Il ne touche pas aux autres sites** qui partageraient la même base de données.

Il fonctionne aussi sur les réseaux de sites (WordPress multisite), et parle français ou anglais selon la langue du site.

---

## Pour aller plus loin (partie technique)

### Prérequis

WP-CLI 2.x, WordPress 6.2 ou plus récent, PHP 7.4 ou plus récent, MySQL ou MariaDB. `mysqldump` (ou `mariadb-dump`) est nécessaire pour `--safe`.

Sans installation, une seule fois : `wp --require=chemin/vers/wp-cli-db-prefix/command.php db-prefix rename --dry-run`.

### Options

| Option | Effet |
|---|---|
| `[<new_prefix>]` | Nouveau préfixe : minuscules, chiffres et tirets bas, commence par une lettre, se termine par un tiret bas. Absent : un préfixe de 4 caractères est tiré au hasard (`random_int()`), sans conflit avec les tables existantes. |
| `--dry-run` | Affiche le plan sans rien modifier. Avec un préfixe tiré au hasard, la commande indique comment relancer avec ce préfixe précis, puisqu'un nouveau est tiré à chaque lancement. |
| `--safe` | Exporte les tables concernées (`wp db export`) et copie `wp-config.php` avant toute modification. Le contenu de l'export est vérifié. Si la sauvegarde échoue, la commande s'arrête sans rien modifier. |
| `--backup-dir=<chemin>` | Dossier de la sauvegarde. Par défaut : `private_html/db-prefix-backups` (Cloudways) ou `db-prefix-backups`, à côté du dossier du site. Un dossier situé dans la racine web est refusé. |
| `--skip-config` | Ne touche pas à `wp-config.php` (préfixe défini par une variable d'environnement, Bedrock, etc.). Le site reste en maintenance : mettez à jour le préfixe là où il est défini, puis lancez `wp maintenance-mode deactivate`. |
| `--yes` | Pas de confirmation (scripts). |

### Déroulement

1. Vérifications : format du préfixe, tables principales (et tables du réseau en multisite), conflits de noms, limite de 64 caractères, vues et déclencheurs (refusés), option `user_roles` de chaque site, clés de métadonnées de compte déjà au nouveau préfixe, ligne `$table_prefix` unique et modifiable dans `wp-config.php`.
2. Avec `--safe` : export SQL vérifié et copie de `wp-config.php`, fichiers aux noms non devinables, droits `600`, dossier protégé par un `.htaccess` et un `index.php` en seconde ligne de défense.
3. Mode maintenance (fichier `.maintenance`).
4. Renommage de toutes les tables en une seule instruction `RENAME TABLE`, atomique. Les clés étrangères suivent.
5. Option `{préfixe}user_roles`, ou `{préfixe}{id}_user_roles` sur chaque site d'un réseau.
6. Clés de métadonnées de compte `{préfixe}*`, en une seule requête.
7. `wp-config.php`, en dernier.
8. Vidage du cache objet.

En cas d'échec, les étapes déjà faites sont annulées dans l'ordre inverse. Si l'annulation elle-même échoue, la commande liste ce qu'il reste à corriger à la main.

### Revenir à l'état sauvegardé

Après un renommage avec `--safe`, la commande affiche les commandes exactes à lancer. Elles suivent ce modèle :

```bash
cp <sauvegarde>/wp-config-<date>.php wp-config.php
wp db import <sauvegarde>/db-prefix-<ancien>_<date>.sql
wp db query "DROP TABLE $(wp db tables '<nouveau>*' --all-tables --format=csv)"
wp cache flush
```

### Traductions

Les messages suivent la langue du site : anglais et français (`fr_FR`, utilisé aussi pour `fr_BE`, `fr_CA`, etc.). Pour ajouter une langue, traduisez `languages/wp-cli-db-prefix.pot`, puis lancez `wp i18n make-mo languages`. L'aide de la commande (`wp help db-prefix rename`) reste en anglais : WP-CLI la lit dans le code et ne la traduit pas.

### Limites

- `CUSTOM_USER_TABLE` / `CUSTOM_USER_META_TABLE` ne sont pas pris en charge (refusés).
- Seule l'option `user_roles` est renommée dans les tables d'options, comme le fait WordPress. Une extension qui stocke une option nommée d'après `$wpdb->prefix` doit être corrigée à la main (cas rare).
- Le vidage du cache objet vide tout le cache, y compris celui des autres sites qui partagent le même serveur Redis.

### Tests

[![Tests](https://github.com/thierrypigot/wp-cli-db-prefix/actions/workflows/tests.yml/badge.svg)](https://github.com/thierrypigot/wp-cli-db-prefix/actions/workflows/tests.yml)

Tests unitaires PHPUnit, et tests d'intégration qui lancent la vraie commande sur un vrai WordPress (site simple et réseau multisite de 4 sites) : renommage, refus, échecs provoqués et annulation, sauvegarde `--safe` puis restauration complète. GitHub Actions les lance à chaque envoi, de PHP 7.4 + WordPress 6.2 à PHP 8.4 + dernière version de WordPress, sur MySQL et MariaDB. Pour les lancer en local : [tests/README.md](tests/README.md).

### Licence

GPL-2.0-or-later. Voir [LICENSE](LICENSE). Historique des versions : [CHANGELOG.md](CHANGELOG.md).
