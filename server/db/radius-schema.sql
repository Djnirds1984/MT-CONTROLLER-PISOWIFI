-- AIRCOINS NETFI — RADIUS database schema (SQLite).
--
-- Mirrors migrateRadius() in server/go/store.go exactly: aircoind creates
-- these tables on first boot, so this file is only needed when the
-- installer prepares radius.db before FreeRADIUS ever starts. Applying it
-- twice is safe (all statements are IF NOT EXISTS / INSERT OR IGNORE).

CREATE TABLE IF NOT EXISTS radacct (
	radacctid INTEGER PRIMARY KEY AUTOINCREMENT,
	acctsessionid VARCHAR(64) NOT NULL,
	acctuniqueid VARCHAR(32) NOT NULL UNIQUE,
	username VARCHAR(64),
	realm VARCHAR(64),
	nasipaddress VARCHAR(15),
	nasportid VARCHAR(15),
	nasporttype VARCHAR(32),
	acctstarttime DATETIME,
	acctupdatetime DATETIME,
	acctstoptime DATETIME,
	acctsessiontime INTEGER,
	acctauthentic VARCHAR(32),
	connectinfo_start VARCHAR(128),
	connectinfo_stop VARCHAR(128),
	acctinputoctets BIGINT,
	acctoutputoctets BIGINT,
	calledstationid VARCHAR(50),
	callingstationid VARCHAR(50),
	servicetype VARCHAR(32),
	framedprotocol VARCHAR(32),
	framedipaddress VARCHAR(15),
	acctterminatecause VARCHAR(32)
);
CREATE INDEX IF NOT EXISTS idx_radacct_user ON radacct (username);
CREATE INDEX IF NOT EXISTS idx_radacct_session ON radacct (acctsessionid, username, nasipaddress);

CREATE TABLE IF NOT EXISTS radcheck (
	id INTEGER PRIMARY KEY AUTOINCREMENT,
	username VARCHAR(64) NOT NULL,
	attribute VARCHAR(64) NOT NULL,
	op CHAR(2) NOT NULL DEFAULT ':=',
	value VARCHAR(253)
);
CREATE INDEX IF NOT EXISTS idx_radcheck_user ON radcheck (username);

CREATE TABLE IF NOT EXISTS radreply (
	id INTEGER PRIMARY KEY AUTOINCREMENT,
	username VARCHAR(64) NOT NULL,
	attribute VARCHAR(64) NOT NULL,
	op CHAR(2) NOT NULL DEFAULT ':=',
	value VARCHAR(253)
);
CREATE INDEX IF NOT EXISTS idx_radreply_user ON radreply (username);

CREATE TABLE IF NOT EXISTS radgroupcheck (
	id INTEGER PRIMARY KEY AUTOINCREMENT,
	groupname VARCHAR(64) NOT NULL,
	attribute VARCHAR(64) NOT NULL,
	op CHAR(2) NOT NULL DEFAULT ':=',
	value VARCHAR(253)
);

CREATE TABLE IF NOT EXISTS radgroupreply (
	id INTEGER PRIMARY KEY AUTOINCREMENT,
	groupname VARCHAR(64) NOT NULL,
	attribute VARCHAR(64) NOT NULL,
	op CHAR(2) NOT NULL DEFAULT ':=',
	value VARCHAR(253)
);

CREATE TABLE IF NOT EXISTS radusergroup (
	username VARCHAR(64) NOT NULL,
	groupname VARCHAR(64) NOT NULL,
	priority INTEGER NOT NULL DEFAULT 0,
	PRIMARY KEY (username, groupname)
);

CREATE TABLE IF NOT EXISTS radpostauth (
	id INTEGER PRIMARY KEY AUTOINCREMENT,
	username VARCHAR(64) NOT NULL,
	pass VARCHAR(64),
	reply VARCHAR(32),
	authdate DATETIME
);

-- Hotspot group defaults; aircoind rewrites these from Admin > Settings.
INSERT OR IGNORE INTO radgroupreply (groupname, attribute, op, value) VALUES
	('hotspot', 'Mikrotik-Rate-Limit', ':=', '2M/2M'),
	('hotspot', 'Idle-Timeout', ':=', '600'),
	('hotspot', 'Acct-Interim-Interval', ':=', '300');
