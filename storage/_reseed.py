"""
Reseed Agenduy SQLite DB — development only
"""
import sqlite3, datetime

DB = r'C:\xampp\htdocs\agenduy.uy\storage\agenduy.db'
conn = sqlite3.connect(DB)
conn.row_factory = sqlite3.Row
cur = conn.cursor()

# Hashes generados con PHP password_hash(..., PASSWORD_BCRYPT, ['cost'=>12])
# Verify: password_verify('...', $hash) en PHP
P_AGENDUY    = '$2y$12$Z3BW0jrKVwAwEmuQ6kQHYONXYdqFAKd7lnO/5M3TwWYd.Xg.QxlNW'
P_ESTETICA   = '$2y$12$dAxfb2dSMb1sp1YoIFs3hu0.wCT4g8uWi/mAFGUnWnWIwT7pN2lA2'
P_BARBERSHOP = '$2y$12$dAxfb2dSMb1sp1YoIFs3hu0.wCT4g8uWi/mAFGUnWnWIwT7pN2lA2'

def insert(table: str, **kw) -> int:
    cols = list(kw.keys())
    vals = list(kw.values())
    cur.execute(f'INSERT INTO {table} ({",".join(cols)}) VALUES ({",".join(["?" for _ in cols])})', vals)
    return cur.lastrowid

# ── clear ───────────────────────────────────────────────────────────────
for t in ['appointments','clients','services','api_keys',
          'subscriptions','commerces','users','memberships','rubros']:
    cur.execute(f'DELETE FROM {t}')
conn.commit()
print('Cleared tables')

# ── rubros ────────────────────────────────────────────────────────────
# Keep in sync with src/Core/db/seed.php (+ carousel assets under src/media/carousel/)
RUBROS = [
    ('Barbería', 'barberia', 'Servicio de peluquería y barbería', 'src/media/carousel/barberias.jpg'),
    ('Abogacía', 'abogados', 'Servicios legales y asesoramiento', 'src/media/carousel/abogados.jpg'),
    ('Belleza y estética', 'belleza', 'Salones y spas', 'src/media/carousel/clinicas_estetica.jpg'),
    ('Clínica de Estética', 'estetica', 'Servicios de belleza y cuidado personal', 'src/media/carousel/clinicas_estetica.jpg'),
    ('Consultorios', 'consultorios', 'Servicios médicos y de salud', 'src/media/carousel/consultorios.jpg'),
    ('Odontología', 'odontologia', 'Consultorios dentales', 'src/media/carousel/dentistas.jpg'),
    ('Dentistas', 'dentistas', 'Servicios odontológicos y cuidado dental', 'src/media/carousel/dentistas.jpg'),
    ('Locales de Eventos', 'eventos', 'Espacios para eventos y celebraciones', 'src/media/carousel/fiestas_eventos.jpg'),
    ('Lavaderos', 'lavaderos', 'Servicios de lavado y limpieza de vehículos', 'src/media/carousel/lavaderos.jpg'),
    ('Profesores Particulares', 'profesores', 'Clases y tutorías personalizadas', 'src/media/carousel/profesionales.jpg'),
    ('Coaching', 'coaches', 'Coaching personal y profesional', 'src/media/carousel/coaches.jpg'),
    ('Emprendedores', 'emprendedores', 'Asesoría para emprendedores', 'src/media/carousel/emprendedores.jpg'),
]
rubro_ids = {}
for nombre, tipo, descripcion, imagen in RUBROS:
    rid = insert('rubros', nombre=nombre, tipo=tipo, descripcion=descripcion, imagen=imagen, activo=1)
    rubro_ids[tipo] = rid
print(f'Rubros: {len(rubro_ids)} -> {rubro_ids}')

r1 = rubro_ids['belleza']
r2 = rubro_ids['barberia']
r3 = rubro_ids['odontologia']


# ── memberships ───────────────────────────────────────────────────────
m_free = insert('memberships',
    nombre='Free', descripcion='Plan gratuito básico',
    precio=0, moneda='UYU', duracion_dias=9999, trial_dias=30, activo=1)
m_basic = insert('memberships',
    nombre='Básico', descripcion='Plan básico profesional',
    precio=299, moneda='UYU', duracion_dias=30, trial_dias=0, activo=1)
m_pro = insert('memberships',
    nombre='Profesional', descripcion='Para negocios establecidos',
    precio=599, moneda='UYU', duracion_dias=30, trial_dias=0, activo=1)
print(f'Memberships: free={m_free} basic={m_basic} pro={m_pro}')

# ── commerces ─────────────────────────────────────────────────────────
today = datetime.date.today()
le_slug = 'la-estetica'
bs_slug = 'barbershop'

cid_le = insert('commerces',
    slug=le_slug, id_rubro=r1,
    id_membership=m_free,
    nombre='La Estética',
    email='contacto@la-estetica.com',
    telefono='+598 99 123 456',
    pais='UY', ciudad='Montevideo',
    calle='Av. 18 de Julio 1234',
    timezone='America/Montevideo',
    status='trial',
    trial_expires_at=(today + datetime.timedelta(days=30)).isoformat(),
    serial='LE-' + datetime.datetime.now().strftime('%Y%m%d%H%M'),
)
cid_bs = insert('commerces',
    slug=bs_slug, id_rubro=r2,
    id_membership=m_basic,
    nombre='Barbershop',
    email='hola@barbershop.com',
    telefono='+598 98 765 432',
    pais='UY', ciudad='Montevideo',
    calle='Bv. España 567',
    timezone='America/Montevideo',
    status='active',
    trial_expires_at=(today + datetime.timedelta(days=30)).isoformat(),
    next_billing_at=(today + datetime.timedelta(days=30)).isoformat(),
    serial='BS-' + datetime.datetime.now().strftime('%Y%m%d%H%M'),
)
print(f'Commerces: la-estetica={cid_le} barbershop={cid_bs}')

# ── users ─────────────────────────────────────────────────────────────
u1 = insert('users',
    role='super_admin', id_commerce=None,
    nombre='Lucas', apellido='Iglesias',
    email='admin@agenduy.uy', telefono='', whatsapp='',
    password_hash=P_AGENDUY, activo=1)
u2 = insert('users',
    role='commerce_admin', id_commerce=cid_le,
    nombre='Lucas', apellido='Iglesias',
    email='lucas.iglesias@la-estetica.agenduy.uy',
    telefono='+598 99 123 456', whatsapp='',
    password_hash=P_ESTETICA, activo=1)
u3 = insert('users',
    role='commerce_admin', id_commerce=cid_bs,
    nombre='Admin', apellido='Barbershop',
    email='barbershop@agenduy.uy',
    telefono='+598 98 765 432', whatsapp='',
    password_hash=P_BARBERSHOP, activo=1)
print(f'Users: super={u1} estetica={u2} barbershop={u3}')

# ── subscriptions ────────────────────────────────────────────────────
insert('subscriptions',
    id_commerce=cid_le, id_membership=m_free,
    status='trial',
    started_at=today.isoformat(),
    trial_expires_at=(today + datetime.timedelta(days=30)).isoformat(),
    current_period_start=today.isoformat(),
    current_period_end=(today + datetime.timedelta(days=30)).isoformat())
insert('subscriptions',
    id_commerce=cid_bs, id_membership=m_basic,
    status='active',
    started_at=today.isoformat(),
    current_period_start=today.isoformat(),
    current_period_end=(today + datetime.timedelta(days=30)).isoformat())
print('Subscriptions created')

# ── services (estado not activo) ─────────────────────────────────────
insert('services',
    id_commerce=cid_le,
    nombre='Limpieza facial',
    descripcion='Limpieza profunda con productos profesionales',
    precio=450.0, duracion_min=45, estado='Activo')
insert('services',
    id_commerce=cid_bs,
    nombre='Corte clasico',
    descripcion='Corte tradicional con acabado perfecto',
    precio=250.0, duracion_min=30, estado='Activo')
insert('services',
    id_commerce=cid_bs,
    nombre='Afeitado con navaja',
    descripcion='Afeitado completo con toalla caliente',
    precio=300.0, duracion_min=40, estado='Activo')
print('Services created')

conn.commit()
print('\n✓ Reseed complete')
print('  Super admin:  admin@agenduy.uy / Agenduy2026!')
print('  La-estetica: lucas.iglesias@la-estetica.agenduy.uy / Test2026!')
print('  Barbershop:  barbershop@agenduy.uy / Test2026!')
