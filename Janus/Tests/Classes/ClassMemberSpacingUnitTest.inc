<?php declare(strict_types=1);

// Valid: Constants in same group without blank lines
class ValidConstGroup {
	public const STATUS_INVALID = 'invalid';
	public const STATUS_OK = 'ok';


	public string $name = 'demo';
}

// Valid: Static properties in same group without blank lines
class ValidStaticGroup {
	public static array $defaultConfig = [];
	protected static array $config = [];


	public string $name = 'demo';
}

// Valid: Properties in same group without blank lines
class ValidPropertyGroup {
	public string $description = 'demo description';
	public string $name = 'demo';
}

// Valid: Methods with 1 blank line
class ValidMethodsOneLine {
	public function first(): void {
	}


	public function second(): void {
	}
}

// Valid: Methods with 2 blank lines
class ValidMethodsTwoLines {
	public function first(): void {
	}


	public function second(): void {
	}
}

// Valid: Docblock counted as member start
class ValidDocblockBoundary {
	public const TYPE = 'demo';


	/** @var string */
	public string $name = 'x';
}

// Invalid: Only 1 blank line between different groups (const -> property)
class InvalidGroupSpacing {
	public const STATUS_OK = 'ok';

	public string $name = 'demo';
}

// Invalid: No blank line between methods
class InvalidMethodsNoLine {
	public function first(): void {
	}
	public function second(): void {
	}
}

// Invalid: More than 2 blank lines between methods
class InvalidMethodsTooMany {
	public function first(): void {
	}



	public function second(): void {
	}
}

// Invalid: Blank line within same property group
class InvalidPropertyGroupSpacing {
	public string $first = 'a';

	public string $second = 'b';
}

// Invalid: Only 1 blank line before docblock (different groups)
class InvalidDocblockBoundary {
	public const TYPE = 'demo';

	/** @var string */
	public string $name = 'x';
}

// Invalid: no blank lines between blocks of different visibility
#[ORM\Entity]
#[ORM\Table(name: 'users')]
#[ORM\UniqueConstraint(name: 'uniq_username', columns: ['username'])]
#[ORM\UniqueConstraint(name: 'uniq_email', columns: ['email'])]
class User {
	/**
	 * Current lifecycle status of the account.
	 *
	 * @var \App\Enum\UserStatus
	 */
	#[ORM\Column(type: 'string', enumType: UserStatus::class)]
	public(set) UserStatus $status;
	/**
	 * Temporary lock state, independent of {@see self::$status}.
	 *
	 * @var \App\Entity\Embeddable\AccountLock
	 */
	#[ORM\Embedded(class: AccountLock::class)]
	protected(set) AccountLock $accountLock;

	/**
	 * Timestamp of the last modification to this record.
	 *
	 * @var \DateTimeImmutable
	 */
	#[ORM\Column(type: 'datetime_immutable')]
	protected private(set) DateTimeImmutable $updatedAt;
	/**
	 * Timestamp the account was deleted, if applicable.
	 *
	 * @var \DateTimeImmutable|null
	 */
	#[ORM\Column(type: 'datetime_immutable', nullable: true)]
	protected private(set) ?DateTimeImmutable $deletedAt = null;


	/**
	 * @param \Symfony\Component\Uid\Uuid $id Immutable global identifier for this user.
	 * @param string $username Unique login name.
	 * @param string $email Unique email address, independent of username.
	 * @param string $firstName Given name.
	 * @param string $lastName Family name.
	 * @param \App\Entity\Embeddable\PasswordCredential $passwordCredential Initial password credential.
	 * @param \DateTimeImmutable $createdAt Timestamp this record was created; also used as the initial value for {@see self::$updatedAt}.
	 */
	public function __construct(
		#[ORM\Id]
		#[ORM\Column(type: 'uuid', unique: true)]
		private(set) readonly Uuid $id,
		#[ORM\Column(type: 'string', length: 180)]
		private(set) readonly string $username,
		#[ORM\Column(type: 'string', length: 180)]
		private(set) readonly string $email,
		#[ORM\Column(type: 'string', length: 120)]
		private(set) readonly string $firstName,
		#[ORM\Column(type: 'string', length: 120)]
		private(set) readonly string $lastName,
		#[ORM\Embedded(class: PasswordCredential::class)]
		private(set) readonly PasswordCredential $passwordCredential,
		#[ORM\Column(type: 'datetime_immutable')]
		private(set) readonly DateTimeImmutable $createdAt,
	) {
		$this->accountLock = new AccountLock();
		$this->status = UserStatus::ACTIVE;
		$this->updatedAt = $this->createdAt;
	}
}

// Invalid: no blank lines between blocks of different visibility and blank lines between same visibility blocks
#[ORM\Entity]
#[ORM\Table(name: 'users')]
#[ORM\UniqueConstraint(name: 'uniq_username', columns: ['username'])]
#[ORM\UniqueConstraint(name: 'uniq_email', columns: ['email'])]
class AnotherUser {
	/**
	 * Current lifecycle status of the account.
	 *
	 * @var \App\Enum\UserStatus
	 */
	#[ORM\Column(type: 'string', enumType: UserStatus::class)]
	public private(set) UserStatus $status;

	/**
	 * Temporary lock state, independent of {@see self::$status}.
	 *
	 * @var \App\Entity\Embeddable\AccountLock
	 */
	#[ORM\Embedded(class: AccountLock::class)]
	public private(set) AccountLock $accountLock;
	/**
	 * Timestamp of the last modification to this record.
	 *
	 * @var \DateTimeImmutable
	 */
	#[ORM\Column(type: 'datetime_immutable')]
	protected private(set) DateTimeImmutable $updatedAt;
	/**
	 * Timestamp the account was deleted, if applicable.
	 *
	 * @var \DateTimeImmutable|null
	 */
	#[ORM\Column(type: 'datetime_immutable', nullable: true)]
	protected private(set) ?DateTimeImmutable $deletedAt = null;


	/**
	 * @param \Symfony\Component\Uid\Uuid $id Immutable global identifier for this user.
	 * @param string $username Unique login name.
	 * @param string $email Unique email address, independent of username.
	 * @param string $firstName Given name.
	 * @param string $lastName Family name.
	 * @param \App\Entity\Embeddable\PasswordCredential $passwordCredential Initial password credential.
	 * @param \DateTimeImmutable $createdAt Timestamp this record was created; also used as the initial value for {@see self::$updatedAt}.
	 */
	public function __construct(
		#[ORM\Id]
		#[ORM\Column(type: 'uuid', unique: true)]
		private(set) readonly Uuid $id,
		#[ORM\Column(type: 'string', length: 180)]
		private(set) readonly string $username,
		#[ORM\Column(type: 'string', length: 180)]
		private(set) readonly string $email,
		#[ORM\Column(type: 'string', length: 120)]
		private(set) readonly string $firstName,
		#[ORM\Column(type: 'string', length: 120)]
		private(set) readonly string $lastName,
		#[ORM\Embedded(class: PasswordCredential::class)]
		private(set) readonly PasswordCredential $passwordCredential,
		#[ORM\Column(type: 'datetime_immutable')]
		private(set) readonly DateTimeImmutable $createdAt,
	) {
		$this->accountLock = new AccountLock();
		$this->status = UserStatus::ACTIVE;
		$this->updatedAt = $this->createdAt;
	}
}