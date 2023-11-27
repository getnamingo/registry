# Namingo Data Encryption

To ensure GDPR compliance, it's crucial for registry owners to secure sensitive registrant data. Encrypting this data in all contact tables is a fundamental step in safeguarding privacy and maintaining data integrity. Below, we outline a comprehensive approach to implement encryption in the Namingo registry, leveraging the robust capabilities of `defuse/php-encryption`.

Installing defuse/php-encryption via Composer:

```bash
composer require defuse/php-encryption
```

## 1. Generate an Encryption Key

Use `keygen.php` to generate an encryption key:

```php
use Defuse\Crypto\Key;

// Generate a random encryption key
$key = Key::createNewRandomKey();

// Save this key securely; you will need it for both encryption and decryption
$keyAscii = $key->saveToAsciiSafeString();

// Output the key so you can copy it
echo $keyAscii;
```

## 2. Save the Key Securely

1. Copy the echoed key.

2. Store the key in an environment variable on your server. For example, add this line to your `~/.bashrc` or `~/.profile`, replacing `your_key_here` with the actual key:

```bash
export NAMINGO_ENCRYPTION_KEY='your_key_here'
```

To ensure the environment variable is retained after reboot, you can add it to your system's profile settings or use a tool like `systemd` to set it as a system-wide environment variable.

## 3. Using the Key for Insert Operations

```php
use Defuse\Crypto\Crypto;
use Defuse\Crypto\Key;

// Load the encryption key from the environment variable
$keyAscii = getenv('NAMINGO_ENCRYPTION_KEY');
$key = Key::loadFromAsciiSafeString($keyAscii);

// Assuming $pdo is your PDO instance
$rawData = "Sensitive Data";
$encryptedData = Crypto::encrypt($rawData, $key);

// Prepare and execute the insert statement
$stmt = $pdo->prepare("INSERT INTO your_table (data_column) VALUES (:data)");
$stmt->bindParam(':data', $encryptedData);
$stmt->execute();
```

## 4. Using the Key for Select Operations

```php
// Assuming $pdo is your PDO instance
$stmt = $pdo->query("SELECT data_column FROM your_table WHERE some_condition");
$row = $stmt->fetch(PDO::FETCH_ASSOC);
$encryptedData = $row['data_column'];

// Decrypt the data
$decryptedData = Crypto::decrypt($encryptedData, $key);

echo $decryptedData;
```