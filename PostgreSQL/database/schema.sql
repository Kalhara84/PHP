-- PostgreSQL schema for the Customer Master program (converted from MySQL dump strbf.sql)
DROP TABLE IF EXISTS cusmast CASCADE;
DROP TABLE IF EXISTS repmast CASCADE;
DROP TABLE IF EXISTS sysmast CASCADE;

CREATE TABLE sysmast (
  syssno     SERIAL UNIQUE,
  sysrtp     INTEGER      NOT NULL DEFAULT 0,      -- record type: 16 = Customer Category, 410 = Credit Terms
  sysrno     VARCHAR(25)  NOT NULL DEFAULT '',
  sysdes     VARCHAR(50)  NOT NULL DEFAULT '',
  sysdes1    VARCHAR(40),
  sysdes2    VARCHAR(40)  NOT NULL DEFAULT '',
  sysdes3    VARCHAR(40)  NOT NULL DEFAULT '',
  syscomsno  INTEGER,
  sysucdnew  VARCHAR(30),
  sysentddt  DATE,
  sysenttime TIME,
  sysucdame  VARCHAR(30),
  sysameddt  DATE,
  sysametime TIME,
  PRIMARY KEY (sysrtp, sysrno)
);

CREATE TABLE repmast (
  repsno     SERIAL UNIQUE,
  repnam     VARCHAR(50)  NOT NULL DEFAULT '',
  reprep     VARCHAR(15)  NOT NULL DEFAULT '' PRIMARY KEY,
  repad1     VARCHAR(50),
  repad2     VARCHAR(50),
  repad3     VARCHAR(50),
  repad4     VARCHAR(50),
  reptel     VARCHAR(15),
  repfax     VARCHAR(15),
  repemail   VARCHAR(30),
  repdepamt  NUMERIC(12,0) NOT NULL DEFAULT 0,
  repinput   INTEGER       NOT NULL DEFAULT 0,
  repcon     INTEGER       NOT NULL DEFAULT 0,
  repidno    VARCHAR(15)   NOT NULL DEFAULT '',
  reppaytype CHAR(1),
  repactyn   CHAR(1)       NOT NULL DEFAULT '',
  repucdnew  VARCHAR(30),
  repentddt  DATE,
  repenttime TIME,
  repucdame  VARCHAR(30),
  repameddt  DATE,
  repametime TIME
);

CREATE TABLE cusmast (
  cussno            SERIAL UNIQUE,
  cuscus            VARCHAR(20)  NOT NULL DEFAULT '' PRIMARY KEY,
  cusnam            VARCHAR(100) NOT NULL,
  cuscatsno         INTEGER      NOT NULL DEFAULT 0,
  cusrepsno         INTEGER      NOT NULL DEFAULT 0,
  cusad1            VARCHAR(40)  NOT NULL DEFAULT '',
  cusad2            VARCHAR(40)  NOT NULL DEFAULT '',
  cusad3            VARCHAR(40)  NOT NULL DEFAULT '',
  cusad4            VARCHAR(40)  NOT NULL DEFAULT '',
  cusad5            VARCHAR(40)  NOT NULL DEFAULT '',
  cusadd            VARCHAR(200) NOT NULL DEFAULT '',
  custelno          VARCHAR(25)  NOT NULL DEFAULT '',
  cusfaxno          VARCHAR(25)  NOT NULL DEFAULT '',
  cusemail          VARCHAR(100) NOT NULL DEFAULT '',
  custinno          VARCHAR(20)  NOT NULL DEFAULT '',
  cusdistrict       VARCHAR(50)  NOT NULL DEFAULT '',
  cuscity           VARCHAR(50)  NOT NULL DEFAULT '',
  cusvatno          VARCHAR(15)  NOT NULL DEFAULT '',
  cussvatno         VARCHAR(25)  NOT NULL DEFAULT '',
  cusjoindate       DATE,
  cusactyn          VARCHAR(1)   NOT NULL DEFAULT '',
  cuscredittmssno   INTEGER      NOT NULL DEFAULT 0,
  cuscreditlimit    NUMERIC(12,0) NOT NULL DEFAULT 0,
  cusrecamt20221231 NUMERIC(12,2) NOT NULL DEFAULT 0,
  cusbalamt20221231 NUMERIC(13,2) NOT NULL DEFAULT 0,
  cusucdnew         VARCHAR(12)  NOT NULL DEFAULT '',
  cusentddt         DATE,
  cusenttime        VARCHAR(12)  NOT NULL DEFAULT '',
  cusucdame         VARCHAR(12)  NOT NULL DEFAULT '',
  cusameddt         DATE,
  cusametime        VARCHAR(12)  NOT NULL DEFAULT '',
  custem1           NUMERIC(14,2) NOT NULL DEFAULT 0,
  custem2           NUMERIC(14,2) NOT NULL DEFAULT 0,
  custem3           NUMERIC(14,2) NOT NULL DEFAULT 0
);
CREATE INDEX cusmast_cusnam_idx ON cusmast (cusnam);
