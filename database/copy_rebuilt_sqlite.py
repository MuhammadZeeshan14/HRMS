"""Copy preserved records into an empty database built by the canonical migrations.

Usage: python database/copy_rebuilt_sqlite.py SNAPSHOT.sqlite REBUILT.sqlite
The destination schema must already exist. This does not change the source.
"""
import json
import sqlite3
import sys
from pathlib import Path

source_path, target_path = [Path(value).resolve() for value in sys.argv[1:3]]
if source_path == target_path or not source_path.is_file() or not target_path.is_file():
    raise ValueError('Use distinct, existing source snapshot and replacement database paths.')

def quote(value):
    return '"' + value.replace('"', '""') + '"'

def table_names(db):
    return [r[0] for r in db.execute("select name from sqlite_master where type='table' and name not like 'sqlite_%' and name != 'migrations'")]

def columns(db, table):
    return [r[1] for r in db.execute('pragma table_info('+quote(table)+')')]

source = sqlite3.connect(f'file:{source_path.as_posix()}?mode=ro', uri=True)
target = sqlite3.connect(target_path)
target.execute('pragma foreign_keys=off')
removed = {'fiscal_years': {'is_current'}}
counts = {}
try:
    target.execute('begin')
    for table in table_names(source):
        if table not in table_names(target):
            raise ValueError('Missing destination table: '+table)
        if target.execute('select count(*) from '+quote(table)).fetchone()[0]:
            raise ValueError('Destination must be empty: '+table)
        source_columns = columns(source, table)
        target_columns = columns(target, table)
        unexpected = set(source_columns) - set(target_columns) - removed.get(table, set())
        if unexpected:
            raise ValueError(f'Unmapped source columns in {table}: {unexpected}')
        fields = [c for c in source_columns if c in target_columns]
        selection = ','.join(quote(c) for c in fields)
        values = source.execute('select '+selection+' from '+quote(table)+' order by rowid').fetchall()
        target.executemany('insert into '+quote(table)+' ('+selection+') values ('+','.join('?' for _ in fields)+')', values)
        copied = target.execute('select '+selection+' from '+quote(table)+' order by rowid').fetchall()
        if values != copied:
            raise ValueError('Record verification failed: '+table)
        counts[table] = len(values)
    for table, sequence in source.execute('select name, seq from sqlite_sequence'):
        if table in counts:
            target.execute('delete from sqlite_sequence where name=?', (table,))
            target.execute('insert into sqlite_sequence(name,seq) values (?,?)', (table, sequence))
    violations = target.execute('pragma foreign_key_check').fetchall()
    if violations:
        raise ValueError('Foreign key violations: '+str(violations))
    if target.execute('pragma integrity_check').fetchone()[0] != 'ok':
        raise ValueError('Replacement integrity check failed')
    target.commit()
    print(json.dumps({'verified_tables': len(counts), 'preserved_rows': counts, 'retired_columns': {'fiscal_years': ['is_current']}, 'foreign_key_violations': 0}, indent=2))
except Exception:
    target.rollback()
    raise
finally:
    target.close()
    source.close()
