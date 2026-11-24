-- Exécuté par Postgres juste après export_base.sql (premier démarrage uniquement).
-- Ces tables existent déjà dans le dump mais leurs migrations n'y sont pas
-- enregistrées : on les marque comme faites pour que `php artisan migrate`
-- ne tente pas de les recréer et applique bien les migrations suivantes.
INSERT INTO public.migrations (migration, batch)
SELECT m, (SELECT COALESCE(MAX(batch), 0) + 1 FROM public.migrations)
FROM (VALUES
    ('2025_09_23_084601_create_journals_table'),
    ('2025_10_14_121233_create_intervalle_comptes_categorie_table')
) AS t(m)
WHERE NOT EXISTS (SELECT 1 FROM public.migrations WHERE migration = t.m);
