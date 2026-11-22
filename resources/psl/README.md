# Public Suffix List

`public_suffix_list.dat` est la liste publique des suffixes
(<https://publicsuffix.org/list/public_suffix_list.dat>), copiée telle quelle.

Elle est versionnée pour que les tests et la CI n'aient aucune dépendance
réseau : `jeremykendall/php-domain-parser` la lit depuis ce fichier via
`PSL_PATH`. La mise à jour est manuelle et trimestrielle, comme la revue des
marques.

Licence : Mozilla Public License 2.0. Le fichier n'est pas modifié.

`app/Services/Abuse/PublicSuffix` retombe sur les deux derniers labels si le
fichier est absent, et journalise l'incident : une PSL manquante ne doit jamais
empêcher une redirection ou un signalement.
