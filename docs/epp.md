# Namingo Registry EPP Server

This section includes examples of commonly used EPP commands for domains, hosts, contacts, and session management. Explore key operations like create, update, delete, transfer, and poll, with practical XML samples for each.

## Commands 

### 1. Session

#### 1.1. Login Request

```xml
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <login>
      <clID>username</clID>
      <pw>password</pw>
      <options>
        <version>1.0</version>
        <lang>en</lang>
      </options>
      <svcs>
        <objURI>urn:ietf:params:xml:ns:domain-1.0</objURI>
        <objURI>urn:ietf:params:xml:ns:contact-1.0</objURI>
        <objURI>urn:ietf:params:xml:ns:host-1.0</objURI>
        <svcExtension>
          <extURI>urn:ietf:params:xml:ns:secDNS-1.1</extURI>
          <extURI>urn:ietf:params:xml:ns:rgp-1.0</extURI>
          <extURI>urn:ietf:params:xml:ns:launch-1.0</extURI>
          <extURI>urn:ietf:params:xml:ns:idn-1.0</extURI>
          <extURI>urn:ietf:params:xml:ns:epp:fee-1.0</extURI>
          <extURI>https://namingo.org/epp/funds-1.0</extURI>
          <extURI>https://namingo.org/epp/identica-1.0</extURI>
        </svcExtension>
      </svcs>
    </login>
    <clTRID>namingo-1234567890-abcdef1234</clTRID>
  </command>
</epp>
```

#### 1.2. Logout Request

```xml
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <logout/>
    <clTRID>namingo-1234567890-abcdef1234</clTRID>
  </command>
</epp>
```

#### 1.3. Hello Request

```xml
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <hello/>
</epp>
```

#### 1.4. Poll Request

```xml
<?xml version="1.0" encoding="UTF-8"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
     xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
     xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <poll op="req"/>
    <clTRID>ABC-12345-XYZ</clTRID>
  </command>
</epp>
```

#### 1.5. Poll Acknowledge

```xml
<?xml version="1.0" encoding="UTF-8"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
     xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
     xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <poll op="ack" msgID="123456"/>
    <clTRID>ABC-67890-XYZ</clTRID>
  </command>
</epp>
```

### 2. Contact

#### 2.1. Contact Check Request

```xml
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <check>
      <contact:check
        xmlns:contact="urn:ietf:params:xml:ns:contact-1.0"
        xsi:schemaLocation="urn:ietf:params:xml:ns:contact-1.0
        contact-1.0.xsd">
        <contact:id>abc-56789</contact:id>
      </contact:check>
    </check>
    <clTRID>namingo-1234567890-abcdef1234</clTRID>
  </command>
</epp>
```

#### 2.2. Contact Create Request

Standard request:

```xml
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <create>
    <contact:create
      xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">
      <contact:id>abc-56789</contact:id>
      <contact:postalInfo type="int">
        <contact:name>Ivan Ivanenko</contact:name>
        <contact:org>LLC "Prykladna Orhanizatsiya"</contact:org>
        <contact:addr>
          <contact:street>Shevchenka St, 10</contact:street>
          <contact:street>Office 5</contact:street>
          <contact:city>Kyiv</contact:city>
          <contact:sp>Kyivska oblast</contact:sp>
          <contact:pc>01001</contact:pc>
          <contact:cc>UA</contact:cc>
        </contact:addr>
      </contact:postalInfo>
      <contact:voice>+380.441234567</contact:voice>
      <contact:fax>+380.442345678</contact:fax>
      <contact:email>example@domain.ua</contact:email>
      <contact:authInfo>
        <contact:pw>D0main$ecret42</contact:pw>
      </contact:authInfo>
    </contact:create>
    </create>
    <clTRID>namingo-1234567890-abcdef1234</clTRID>
  </command>
</epp>
```

Request with Identica extension:

```xml
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <create>
    <contact:create
      xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">
      <contact:id>abc-56789</contact:id>
      <contact:postalInfo type="int">
        <contact:name>Ivan Ivanenko</contact:name>
        <contact:org>LLC "Prykladna Orhanizatsiya"</contact:org>
        <contact:addr>
          <contact:street>Shevchenka St, 10</contact:street>
          <contact:street>Office 5</contact:street>
          <contact:city>Kyiv</contact:city>
          <contact:sp>Kyivska oblast</contact:sp>
          <contact:pc>01001</contact:pc>
          <contact:cc>UA</contact:cc>
        </contact:addr>
      </contact:postalInfo>
      <contact:voice>+380.441234567</contact:voice>
      <contact:fax>+380.442345678</contact:fax>
      <contact:email>example@domain.ua</contact:email>
      <contact:authInfo>
        <contact:pw>D0main$ecret42</contact:pw>
      </contact:authInfo>
    </contact:create>
    </create>
    <extension>
      <identica:create
        xmlns:identica="https://namingo.org/epp/identica-1.0"
        xsi:schemaLocation="https://namingo.org/epp/identica-1.0 identica-1.0.xsd">
        <identica:nin type="business">1234567890</identica:nin>
      </identica:create>
    </extension>
    <clTRID>namingo-1234567890-abcdef1234</clTRID>
  </command>
</epp>
```

#### 2.3. Contact Info Request

```xml
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <info>
      <contact:info
       xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">
        <contact:id>abc-56789</contact:id>
      </contact:info>
    </info>
    <clTRID>namingo-1234567890-abcdef1234</clTRID>
  </command>
</epp>
```

#### 2.4. Contact Update Request

Standard request:

```xml
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <update>
      <contact:update
      xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">
        <contact:id>abc-12345</contact:id>
        <contact:chg>
          <contact:postalInfo type="int">
            <contact:name>Petro Petrenko</contact:name>
            <contact:org>LLC "Nova Orhanizatsiya"</contact:org>
            <contact:addr>
              <contact:street>Hrushevskoho St, 15</contact:street>
              <contact:street>Building B</contact:street>
              <contact:street>Suite 12</contact:street>
              <contact:city>Odesa</contact:city>
              <contact:sp>Odeska oblast</contact:sp>
              <contact:pc>65000</contact:pc>
              <contact:cc>UA</contact:cc>
            </contact:addr>
          </contact:postalInfo>
          <contact:voice>+380.482123456</contact:voice>
          <contact:fax>+380.482654321</contact:fax>
          <contact:email>example@newdomain.ua</contact:email>
        </contact:chg>
      </contact:update>
    </update>
    <clTRID>namingo-1234567890-abcdef1234</clTRID>
  </command>
</epp>
```

Request with Identica extension:

```xml
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <update>
      <contact:update
      xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">
        <contact:id>abc-12345</contact:id>
        <contact:chg>
          <contact:postalInfo type="int">
            <contact:name>Petro Petrenko</contact:name>
            <contact:org>LLC "Nova Orhanizatsiya"</contact:org>
            <contact:addr>
              <contact:street>Hrushevskoho St, 15</contact:street>
              <contact:street>Building B</contact:street>
              <contact:street>Suite 12</contact:street>
              <contact:city>Odesa</contact:city>
              <contact:sp>Odeska oblast</contact:sp>
              <contact:pc>65000</contact:pc>
              <contact:cc>UA</contact:cc>
            </contact:addr>
          </contact:postalInfo>
          <contact:voice>+380.482123456</contact:voice>
          <contact:fax>+380.482654321</contact:fax>
          <contact:email>example@newdomain.ua</contact:email>
        </contact:chg>
      </contact:update>
    </update>
    <extension>
      <identica:update
        xmlns:identica="https://namingo.org/epp/identica-1.0"
        xsi:schemaLocation="https://namingo.org/epp/identica-1.0 identica-1.0.xsd">
        <identica:nin type="personal">1234567890</identica:nin>
      </identica:update>
    </extension>
    <clTRID>namingo-1234567890-abcdef1234</clTRID>
  </command>
</epp>
```

#### 2.5. Contact Delete Request

```xml
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <delete>
      <contact:delete
       xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">
        <contact:id>abc-12398</contact:id>
      </contact:delete>
    </delete>
    <clTRID>namingo-1234567890-abcdef1234</clTRID>
  </command>
</epp>
```

### 3. Host

#### 3.1. Host Check Request

```xml
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <check>
      <host:check
        xmlns:host="urn:ietf:params:xml:ns:host-1.0"
        xsi:schemaLocation="urn:ietf:params:xml:ns:host-1.0 host-1.0.xsd">
        <host:name>ns1.example.test</host:name>
      </host:check>
    </check>
    <clTRID>namingo-1234567890-abcdef1234</clTRID>
  </command>
</epp>
```

#### 3.2. Host Create Request

```xml
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <create>
      <host:create
       xmlns:host="urn:ietf:params:xml:ns:host-1.0">
        <host:name>ns1.example.test</host:name>
        <host:addr ip="v4">192.0.2.1</host:addr>
      </host:create>
    </create>
    <clTRID>namingo-1234567890-abcdef1234</clTRID>
  </command>
</epp>
```

#### 3.3. Host Info Request

```xml
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <info>
      <host:info
       xmlns:host="urn:ietf:params:xml:ns:host-1.0">
        <host:name>ns1.example.test</host:name>
      </host:info>
    </info>
    <clTRID>namingo-1234567890-abcdef1234</clTRID>
  </command>
</epp>
```

#### 3.4. Host Update Request

```xml
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <update>
      <host:update
       xmlns:host="urn:ietf:params:xml:ns:host-1.0">
        <host:name>ns1.example.test</host:name>
        <host:add>
          <host:addr ip="v4">198.51.100.2</host:addr>
        </host:add>
        <host:rem>
          <host:addr ip="v4">192.0.2.1</host:addr>
        </host:rem>
      </host:update>
    </update>
    <clTRID>namingo-1234567890-abcdef1234</clTRID>
  </command>
</epp>
```

#### 3.5. Host Delete Request

```xml
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <delete>
      <host:delete
       xmlns:host="urn:ietf:params:xml:ns:host-1.0">
        <host:name>ns2.example.test</host:name>
      </host:delete>
    </delete>
    <clTRID>namingo-1234567890-abcdef1234</clTRID>
  </command>
</epp>
```

### 4. Domain

#### 4.1. Domain Check Request

Standard request:

```xml
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <check>
      <domain:check
        xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
        xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
        <domain:name>example.test</domain:name>
        <domain:name>example.example</domain:name>
      </domain:check>
    </check>
    <clTRID>namingo-1234567890-abcdef1234</clTRID>
  </command>
</epp>
```

Check for claims:

```xml
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <check>
      <domain:check
        xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
        xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
        <domain:name>example.test</domain:name>
        <domain:name>example.example</domain:name>
      </domain:check>
    </check>
    <extension>
      <launch:check xmlns:launch="urn:ietf:params:xml:ns:launch-1.0" 
       type="claims">
        <launch:phase>claims</launch:phase>
      </launch:check>
    </extension>
    <clTRID>namingo-1234567890-abcdef1234</clTRID>
  </command>
</epp>
```

#### 4.2. Domain Create Request

Standard request:

```xml
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <create>
      <domain:create
       xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
        <domain:name>myexample.test</domain:name>
        <domain:period unit="y">1</domain:period>
        <domain:ns>
          <domain:hostObj>ns1.example.example</domain:hostObj>
          <domain:hostObj>ns2.example.example</domain:hostObj>
        </domain:ns>
        <domain:registrant>abc-56789</domain:registrant>
        <domain:contact type="admin">abc-12345</domain:contact>
        <domain:contact type="tech">abc-12345</domain:contact>
        <domain:contact type="billing">abc-12345</domain:contact>
        <domain:authInfo>
          <domain:pw>D0main$ecret42</domain:pw>
        </domain:authInfo>
      </domain:create>
    </create>
    <clTRID>namingo-1234567890-abcdef1234</clTRID>
  </command>
</epp>
```

Request with DNSSEC:

```xml
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <create>
      <domain:create
       xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
        <domain:name>myexample.test</domain:name>
        <domain:period unit="y">1</domain:period>
        <domain:ns>
          <domain:hostObj>ns1.example.example</domain:hostObj>
          <domain:hostObj>ns2.example.example</domain:hostObj>
        </domain:ns>
        <domain:registrant>abc-56789</domain:registrant>
        <domain:contact type="admin">abc-12345</domain:contact>
        <domain:contact type="tech">abc-12345</domain:contact>
        <domain:contact type="billing">abc-12345</domain:contact>
        <domain:authInfo>
          <domain:pw>D0main$ecret42</domain:pw>
        </domain:authInfo>
      </domain:create>
    </create>
    <extension>
      <secDNS:create xmlns:secDNS="urn:ietf:params:xml:ns:secDNS-1.1">
        <secDNS:add>
          <secDNS:dsData>
            <secDNS:keyTag>12345</secDNS:keyTag>
            <secDNS:alg>8</secDNS:alg>
            <secDNS:digestType>2</secDNS:digestType>
            <secDNS:digest>49FD46E6C4B45C55D4AC93CE4721E8C6DB6FAB1D</secDNS:digest>
          </secDNS:dsData>
          <secDNS:dsData>
            <secDNS:keyTag>67890</secDNS:keyTag>
            <secDNS:alg>13</secDNS:alg>
            <secDNS:digestType>2</secDNS:digestType>
            <secDNS:digest>3A5B4C2D75E3F58B907BD2318D3470FBC9038D40</secDNS:digest>
          </secDNS:dsData>
        </secDNS:add>
      </secDNS:create>
    </extension>
    <clTRID>namingo-1234567890-abcdef1234</clTRID>
  </command>
</epp>
```

Request with claims:

```xml
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <create>
      <domain:create
       xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
        <domain:name>myexample.test</domain:name>
        <domain:period unit="y">1</domain:period>
        <domain:ns>
          <domain:hostObj>ns1.example.example</domain:hostObj>
          <domain:hostObj>ns2.example.example</domain:hostObj>
        </domain:ns>
        <domain:registrant>abc-56789</domain:registrant>
        <domain:contact type="admin">abc-12345</domain:contact>
        <domain:contact type="tech">abc-12345</domain:contact>
        <domain:contact type="billing">abc-12345</domain:contact>
        <domain:authInfo>
          <domain:pw>D0main$ecret42</domain:pw>
        </domain:authInfo>
      </domain:create>
    </create>
    <extension>
      <launch:create xmlns:launch="urn:ietf:params:xml:ns:launch-1.0">
        <launch:phase>claims</launch:phase>
        <launch:notice>
          <launch:noticeID>ABC-12345678-XYZ</launch:noticeID>
          <launch:notAfter>2024-12-31T23:59:59Z</launch:notAfter>
          <launch:acceptedDate>2024-11-28T14:30:00Z</launch:acceptedDate>
        </launch:notice>
      </launch:create>
    </extension>
    <clTRID>namingo-1234567890-abcdef1234</clTRID>
  </command>
</epp>
```

Request for sunrise:

```xml
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <create>
      <domain:create
       xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
        <domain:name>myexample.test</domain:name>
        <domain:period unit="y">1</domain:period>
        <domain:ns>
          <domain:hostObj>ns1.example.example</domain:hostObj>
          <domain:hostObj>ns2.example.example</domain:hostObj>
        </domain:ns>
        <domain:registrant>abc-56789</domain:registrant>
        <domain:contact type="admin">abc-12345</domain:contact>
        <domain:contact type="tech">abc-12345</domain:contact>
        <domain:contact type="billing">abc-12345</domain:contact>
        <domain:authInfo>
          <domain:pw>D0main$ecret42</domain:pw>
        </domain:authInfo>
      </domain:create>
    </create>
    <extension>
      <launch:create xmlns:launch="urn:ietf:params:xml:ns:launch-1.0" type="application">
        <launch:phase>sunrise</launch:phase>
        <smd:encodedSignedMark xmlns:smd="urn:ietf:params:xml:ns:signedMark-1.0">
          PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iVVRGLTgiPz4KPHNnbTpzaWduZWRNYXJrIHhtbG5zOnNnbT0idXJuOmlldGY6cGFyYW1zOnhtbDpuczpzaWduZWRNYXJrLTEuMCI+CiAgPHNnbTp0bWNoSWRlbnRpdHk+RG9tYWluTmFtZS5jb208L3NnbTp0bWNoSWRlbnRpdHk+CiAgPHNnbTp0bWNoRGV0YWlscz4KICAgIDxzZ206dG1jaFN0YXR1cz5WYWxpZDwvc2dtOnRtY2hTdGF0dXM+CiAgPC9zZ206dG1jaERldGFpbHM+CiAgPHNnbTpzaWduYXR1cmU+CiAgICA8c2dtOnNpZ25hdHVyZU1ldGhvZD5yc2Etc2hhMjU2PC9zZ206c2lnbmF0dXJlTWV0aG9kPgogICAgPHNnbTpkaWdlc3Q+...base64encodeddata...
        </smd:encodedSignedMark>
      </launch:create>
    </extension>
    <clTRID>namingo-1234567890-abcdef1234</clTRID>
  </command>
</epp>
```

#### 4.3. Domain Info Request

Standard request:

```xml
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <info>
      <domain:info
       xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
        <domain:name hosts="all">myexample.test</domain:name>
        <domain:authInfo>
          <domain:pw>authInfoPw</domain:pw>
        </domain:authInfo>
      </domain:info>
    </info>
    <clTRID>namingo-1234567890-abcdef1234</clTRID>
  </command>
</epp>
```

Response with Identica in database:

```xml
...
<extension>
  <identica:infData
    xmlns:identica="https://namingo.org/epp/identica-1.0"
    xsi:schemaLocation="https://namingo.org/epp/identica-1.0 identica-1.0.xsd">
    <identica:nin type="personal">1234567890</identica:nin>
  </identica:infData>
</extension>
...
```

#### 4.4. Domain Update Request

```xml
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
      xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
      xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
 <command>
   <update>
     <domain:update
           xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
           xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
       <domain:name>myexample.test</domain:name>
       <domain:add>
         <domain:ns>
           <domain:hostObj>ns1.example.example</domain:hostObj>
         </domain:ns>
         <domain:contact type="billing">bcd-92345</domain:contact>
         <domain:status s="clientTransferProhibited"/>
       </domain:add>
       <domain:rem>
         <domain:ns>
           <domain:hostObj>ns3.example.example</domain:hostObj>
         </domain:ns>
         <domain:contact type="admin">bcd-92345</domain:contact>
         <domain:status s="clientUpdateProhibited"/>
       </domain:rem>
       <domain:chg>
         <domain:registrant>bcd-92345</domain:registrant>
         <domain:authInfo>
           <domain:pw>D0main$ecret42</domain:pw>
         </domain:authInfo>
       </domain:chg>
     </domain:update>
     <extension>
       <secdns:update xmlns:secdns="urn:ietf:params:xml:ns:secDNS-1.1">
         <secdns:add>
           <secdns:dsData>
             <secdns:keyTag>12345</secdns:keyTag>
             <secdns:alg>8</secdns:alg>
             <secdns:digestType>2</secdns:digestType>
             <secdns:digest>49FD46E6C4B45C55D4AC</secdns:digest>
           </secdns:dsData>
         </secdns:add>
         <secdns:rem>
           <secdns:dsData>
             <secdns:keyTag>67890</secdns:keyTag>
             <secdns:alg>8</secdns:alg>
             <secdns:digestType>2</secdns:digestType>
             <secdns:digest>23AB56C7D8EF9AB34A12</secdns:digest>
           </secdns:dsData>
         </secdns:rem>
       </secdns:update>
     </extension>
   </update>
   <clTRID>namingo-1234567890-abcdef1234</clTRID>
 </command>
</epp>
```

#### 4.5. Domain Renew Request

```xml
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <renew>
      <domain:renew
       xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
        <domain:name>myexample.test</domain:name>
        <domain:curExpDate>2024-10-01</domain:curExpDate>
        <domain:period unit="y">1</domain:period>
      </domain:renew>
    </renew>
    <clTRID>namingo-1234567890-abcdef1234</clTRID>
  </command>
</epp>
```

#### 4.6. Domain Transfer Request

Request:

```xml
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <transfer op="request">
      <domain:transfer
       xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
        <domain:name>myexample.test</domain:name>
        <domain:period unit="y">1</domain:period>
        <domain:authInfo>
          <domain:pw>D0main$ecret42</domain:pw>
        </domain:authInfo>
      </domain:transfer>
    </transfer>
    <clTRID>namingo-1234567890-abcdef1234</clTRID>
  </command>
</epp>
```

Query:

```xml
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <transfer op="query">
      <domain:transfer
       xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
       xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
        <domain:name>myexample.test</domain:name>
      </domain:transfer>
    </transfer>
    <clTRID>namingo-1234567890-abcdef1234</clTRID>
  </command>
</epp>
```

Approve:

```xml
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <transfer op="approve">
      <domain:transfer
       xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
       xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
        <domain:name>myexample.test</domain:name>
      </domain:transfer>
    </transfer>
    <clTRID>namingo-1234567890-abcdef1234</clTRID>
  </command>
</epp>
```

Cancel:

```xml
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <transfer op="cancel">
      <domain:transfer
       xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
       xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
        <domain:name>myexample.test</domain:name>
      </domain:transfer>
    </transfer>
    <clTRID>namingo-1234567890-abcdef1234</clTRID>
  </command>
</epp>
```

Reject:

```xml
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <transfer op="reject">
      <domain:transfer
       xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
       xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
        <domain:name>myexample.test</domain:name>
      </domain:transfer>
    </transfer>
    <clTRID>namingo-1234567890-abcdef1234</clTRID>
  </command>
</epp>
```

#### 4.7. Domain Delete Request

```xml
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <delete>
      <domain:delete
       xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
        <domain:name>myexample.test</domain:name>
      </domain:delete>
    </delete>
    <clTRID>namingo-1234567890-abcdef1234</clTRID>
  </command>
</epp>
```

#### 4.8. Domain Restore Request

Request:

```xml
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <update>
      <domain:update xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
        <domain:name>example.test</domain:name>
      </domain:update>
    </update>
    <extension>
      <rgp:update xmlns:rgp="urn:ietf:params:xml:ns:rgp-1.0">
        <rgp:restore op="request"/>
      </rgp:update>
    </extension>
    <clTRID>namingo-1234567890-abcdef1234</clTRID>
  </command>
</epp>
```

Report:

```xml
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0
  epp-1.0.xsd">
  <command>
    <update>
      <domain:update
       xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
       xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0
       domain-1.0.xsd">
        <domain:name>example.test</domain:name>
      </domain:update>
    </update>
    <extension>
      <rgp:update xmlns:rgp="urn:ietf:params:xml:ns:rgp-1.0"
       xsi:schemaLocation="urn:ietf:params:xml:ns:rgp-1.0
       rgp-1.0.xsd">
        <rgp:restore op="report">
          <rgp:report>
            <rgp:preData>Pre-delete registration data goes here.
            Both XML and free text are allowed.</rgp:preData>
            <rgp:postData>Post-restore registration data goes here.
            Both XML and free text are allowed.</rgp:postData>
            <rgp:delTime>2019-10-10T22:00:00.0Z</rgp:delTime>
            <rgp:resTime>2019-10-20T22:00:00.0Z</rgp:resTime>
            <rgp:resReason>Registrant error.</rgp:resReason>
            <rgp:statement>This registrar has not restored the
            Registered Name in order to assume the rights to use
            or sell the Registered Name for itself or for any
            third party.</rgp:statement>
            <rgp:statement>The information in this report is
            true to best of this registrars knowledge, and this
            registrar acknowledges that intentionally supplying
            false information in this report shall constitute an
            incurable material breach of the
            Registry-Registrar Agreement.</rgp:statement>
            <rgp:other>Supporting information goes
            here.</rgp:other>
          </rgp:report>
        </rgp:restore>
      </rgp:update>
    </extension>
    <clTRID>namingo-1234567890-abcdef1234</clTRID>
  </command>
</epp>
```

### 5. Extensions

#### 5.1. Funds Info Request

Request:

```xml
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <info>
      <funds:info
        xmlns:funds="https://namingo.org/epp/funds-1.0">
      </funds:info>
    </info>
    <clTRID>namingo-1234567890-abcdef1234</clTRID>
  </command>
</epp>
```

Response:

```xml
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
    <response>
      <result code="1000">
        <msg>Command completed successfully</msg>
      </result>
      <resData>
        <funds:infData 
         xmlns:funds="https://namingo.org/epp/funds-1.0"
         xsi:schemaLocation="https://namingo.org/epp/funds-1.0 funds-1.0.xsd">
          <funds:balance>100000.00</funds:balance>
          <funds:currency>USD</funds:currency>
          <funds:availableCredit>100000.00</funds:availableCredit>
          <funds:creditLimit>100000.00</funds:creditLimit>
          <funds:creditThreshold>
            <funds:fixed>500.00</funds:fixed>
          </funds:creditThreshold>
        </funds:infData>
      </resData>
      <trID>
        <clTRID>client-20241128-12345</clTRID>
        <svTRID>namingo-1234567890-abcdef1234</svTRID>
      </trID>
    </response>
</epp>
```