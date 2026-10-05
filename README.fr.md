# WP-CLI DB Prefix

[English](README.md)

Commande WP-CLI qui renomme sans risque le préfixe des tables WordPress (`wp_` par défaut), sur un site simple ou un réseau multisite.

Elle vérifie tout avant de modifier quoi que ce soit, renomme toutes les tables en une seule instruction atomique, réécrit `wp-config.php` en dernier et annule automatiquement les modifications si une étape échoue.

## Prérequis

- WP-CLI 2.x
- WordPress 6.2 ou une version plus récente
- PHP 7.4 ou une version plus récente
- MySQL ou MariaDB

## Installation

En paquet WP-CLI, installé pour l'utilisateur système courant :

```bash
wp package install thierrypigot/wp-cli-db-prefix
```

Ou ponctuellement, sans rien installer :

```bash
git clone https://github.com/thierrypigot/wp-cli-db-prefix.git
wp --require=wp-cli-db-prefix/command.php db-prefix rename wawp7k_ --dry-run
```

## Utilisation

```bash
# 1. Sauvegarder.
wp db export avant-prefixe.sql
cp wp-config.php wp-config.php.avant-prefixe

# 2. Simuler.
wp db-prefix rename wawp7k_ --dry-run

# 3. Renommer (confirmation demandée).
wp db-prefix rename wawp7k_
```

Le fichier `wp-config.php.avant-prefixe` contient les identifiants de la base de données : supprimez-le une fois le site vérifié.

Sur un réseau multisite, lancez la commande une seule fois, depuis le site principal du réseau.

### Options

| Option | Effet |
|---|---|
| `--dry-run` | Affiche le plan sans rien modifier. |
| `--skip-config` | Ne touche pas à `wp-config.php`, pour un préfixe défini ailleurs (variable d'environnement, Bedrock, etc.). Le site reste alors en mode maintenance (503) : mettez à jour le préfixe là où il est défini, puis lancez `wp maintenance-mode deactivate`. Sans ça, WordPress afficherait l'écran d'installation à n'importe quel visiteur. |
| `--yes` | Pas de confirmation (scripts). |

Le nouveau préfixe ne doit contenir que des lettres minuscules, des chiffres et des tirets bas, commencer par une lettre et se terminer par un tiret bas.

## Ce que fait la commande

1. **Vérifie tout avant de modifier quoi que ce soit :**
   - format du préfixe ;
   - présence des tables principales (et des tables du réseau en multisite) ;
   - aucune table ne porte déjà un des nouveaux noms ;
   - aucun nom ne dépasse 64 caractères ;
   - aucune vue ni aucun déclencheur sur les tables (refusés) ;
   - option `user_roles` présente sur chaque site ;
   - aucune métadonnée utilisateur ne commence déjà par le nouveau préfixe ;
   - une seule ligne `$table_prefix`, modifiable, dans `wp-config.php`.
2. **Met le site en mode maintenance** (fichier `.maintenance`), pour que les visiteurs reçoivent une page 503 plutôt qu'une erreur ou l'écran d'installation.
3. **Renomme toutes les tables en une seule instruction `RENAME TABLE`.** MySQL renomme tout ou rien, et les clés étrangères suivent.
4. Renomme l'option `{préfixe}user_roles`, ou `{préfixe}{id}_user_roles` sur chaque site d'un réseau.
5. Renomme les métadonnées utilisateur `{préfixe}*` (capacités, niveau, réglages, `{préfixe}{id}_*` sur un réseau) en une seule requête.
6. **Réécrit `wp-config.php` en dernier.**
7. Vide le cache objet (Redis, Memcached).

Si une étape échoue, les étapes déjà faites sont annulées dans l'ordre inverse, et la commande le confirme. Si l'annulation elle-même échoue, la commande liste ce qu'il reste à corriger à la main.

**Autre installation dans la même base :** si la base contient aussi un site dont le préfixe commence par le vôtre (par exemple `wp_shop_` alors que vous renommez `wp_`), ses tables sont détectées, listées et laissées intactes.

## Traductions

Les messages suivent la langue du site. L'anglais et le français (`fr_FR`) sont fournis, et les autres variantes du français (`fr_BE`, `fr_CA`...) utilisent `fr_FR`. Pour ajouter une langue, traduisez `languages/wp-cli-db-prefix.pot`, puis générez le fichier `.mo` avec `wp i18n make-mo languages`.

L'aide de la commande (`wp help db-prefix rename`) reste en anglais : WP-CLI la lit dans le code et ne la traduit pas.

## Limites

- `CUSTOM_USER_TABLE` / `CUSTOM_USER_META_TABLE` ne sont pas pris en charge (refusés).
- Seule l'option `user_roles` est renommée dans les tables d'options, comme le fait WordPress lui-même. Une extension qui stocke une option nommée d'après `$wpdb->prefix` doit être corrigée à la main (cas rare).
- Le vidage du cache objet vide tout le cache (comme `wp cache flush`), y compris celui des autres sites qui partagent le même serveur Redis.

## Développement

Testé sur WordPress 7.1 avec MariaDB 11.4, sur un site simple et sur un réseau multisite de 4 sites (dont un archivé), avec des échecs provoqués pour vérifier l'annulation.

## Licence

GPL-2.0-or-later. Voir [LICENSE](LICENSE).

Développé par [Thierry Pigot](https://wearewp.pro), WeAre[WP].
