SELECT 'CREATE DATABASE testing'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'testing')\gexec

SELECT format('CREATE DATABASE %I', name)
FROM unnest(ARRAY[
    'testing_a', 'testing_b', 'testing_c', 'testing_d', 'testing_e', 'testing_f', 'testing_g',
    'testing_h1', 'testing_h2', 'testing_h3', 'testing_h4', 'testing_i'
]) AS name
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = name)\gexec
