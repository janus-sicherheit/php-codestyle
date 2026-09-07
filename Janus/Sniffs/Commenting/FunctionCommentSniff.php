<?php


namespace Janus\Sniffs\Commenting;


use PHP_CodeSniffer\Config;
use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Standards\PEAR\Sniffs\Commenting\FunctionCommentSniff as PEARFunctionCommentSniff;
use PHP_CodeSniffer\Util\Common;


class FunctionCommentSniff extends PEARFunctionCommentSniff {
	/**
	 * Whether to skip inheritdoc comments.
	 *
	 * @var bool
	 */
	public bool $skipIfInheritdoc = false;
	/**
	 * The current PHP version.
	 *
	 * @var string|int|null
	 */
	private int|string|null $phpVersion = null;


	/**
	 * Process the return comment of this function comment.
	 *
	 * @param \PHP_CodeSniffer\Files\File $phpcsFile The file being scanned.
	 * @param int $stackPtr The position of the current token in the stack passed in $tokens.
	 * @param int $commentStart The position in the stack where the comment started.
	 * @return void
	 */
	protected function processReturn(File $phpcsFile, int $stackPtr, int $commentStart): void {
		$tokens = $phpcsFile->getTokens();
		$return = null;

		if ($this->skipIfInheritdoc === true) {
			if ($this->checkInheritdoc($phpcsFile, $stackPtr, $commentStart) === true) {
				return;
			}
		}

		foreach ($tokens[ $commentStart ]['comment_tags'] as $tag) {
			if ($tokens[ $tag ]['content'] === '@return') {
				if ($return !== null) {
					$error = 'Only 1 @return tag is allowed in a function comment';
					$phpcsFile->addError($error, $tag, 'DuplicateReturn');

					return;
				}

				$return = $tag;
			}
		}

		// Skip constructor and destructor.
		$methodName = $phpcsFile->getDeclarationName($stackPtr);
		$isSpecialMethod = in_array($methodName, $this->specialMethods, true);

		if ($return !== null) {
			$content = $tokens[ $return + 2 ]['content'];
			if (empty($content) === true || $tokens[ $return + 2 ]['code'] !== T_DOC_COMMENT_STRING) {
				$error = 'Return type missing for @return tag in function comment';
				$phpcsFile->addError($error, $return, 'MissingReturnType');
			}
			else {
				// Support both a return type and a description.
				preg_match('`^((?:\|?(?:array\([^\)]*\)|[\\\\a-z0-9_\[\]]+))*)( .*)?`i', $content, $returnParts);
				if (isset($returnParts[1]) === false) {
					return;
				}

				$returnType = $returnParts[1];

				// Check return type (can be multiple, separated by '|').
				$typeNames = explode('|', $returnType);
				$suggestedNames = [];
				foreach ($typeNames as $typeName) {
					$suggestedName = static::suggestType($typeName);
					if (in_array($suggestedName, $suggestedNames, true) === false) {
						$suggestedNames[] = $suggestedName;
					}
				}

				$suggestedType = implode('|', $suggestedNames);
				if ($returnType !== $suggestedType) {
					$error = 'Expected "%s" but found "%s" for function return type';
					$data = [
						$suggestedType,
						$returnType,
					];
					$fix = $phpcsFile->addFixableError($error, $return, 'InvalidReturn', $data);
					if ($fix === true) {
						$replacement = $suggestedType;
						if (empty($returnParts[2]) === false) {
							$replacement .= $returnParts[2];
						}

						$phpcsFile->fixer->replaceToken($return + 2, $replacement);
						unset($replacement);
					}
				}

				// If the return type is void, make sure there is
				// no return statement in the function.
				if ($returnType === 'void') {
					if (isset($tokens[ $stackPtr ]['scope_closer']) === true) {
						$endToken = $tokens[ $stackPtr ]['scope_closer'];
						for ($returnToken = $stackPtr; $returnToken < $endToken; $returnToken++) {
							if (
								$tokens[ $returnToken ]['code'] === T_CLOSURE
								|| $tokens[ $returnToken ]['code'] === T_ANON_CLASS
							) {
								$returnToken = $tokens[ $returnToken ]['scope_closer'];
								continue;
							}

							if (
								$tokens[ $returnToken ]['code'] === T_RETURN
								|| $tokens[ $returnToken ]['code'] === T_YIELD
								|| $tokens[ $returnToken ]['code'] === T_YIELD_FROM
							) {
								break;
							}
						}

						if ($returnToken !== $endToken) {
							// If the function is not returning anything, just
							// exiting, then there is no problem.
							$semicolon = $phpcsFile->findNext(T_WHITESPACE, $returnToken + 1, null, true);
							if ($tokens[ $semicolon ]['code'] !== T_SEMICOLON) {
								$error = 'Function return type is void, but function contains return statement';
								$phpcsFile->addError($error, $return, 'InvalidReturnVoid');
							}
						}
					}
				}
				elseif (
					$returnType !== 'mixed'
					&& $returnType !== 'never'
					&& in_array('void', $typeNames, true) === false
				) {
					// If return type is not void, never, or mixed, there needs to be a
					// return statement somewhere in the function that returns something.
					if (isset($tokens[ $stackPtr ]['scope_closer']) === true) {
						$endToken = $tokens[ $stackPtr ]['scope_closer'];
						$hasReturn = false;
						$hasGeneratorYield = false;
						$voidReturnToken = null;

						for ($returnToken = $stackPtr; $returnToken < $endToken; $returnToken++) {
							if (
								$tokens[ $returnToken ]['code'] === T_CLOSURE
								|| $tokens[ $returnToken ]['code'] === T_ANON_CLASS
							) {
								$returnToken = $tokens[ $returnToken ]['scope_closer'];
								continue;
							}

							if ($tokens[ $returnToken ]['code'] === T_YIELD || $tokens[ $returnToken ]['code'] === T_YIELD_FROM) {
								$hasGeneratorYield = true;
								continue;
							}

							if ($tokens[ $returnToken ]['code'] === T_RETURN) {
								$hasReturn = true;
								$semicolon = $phpcsFile->findNext(T_WHITESPACE, $returnToken + 1, null, true);

								if ($tokens[ $semicolon ]['code'] === T_SEMICOLON && $voidReturnToken === null) {
									$voidReturnToken = $returnToken;
								}
							}
						}

						if ($hasGeneratorYield === false) {
							if ($hasReturn === false) {
								$error = 'Function return type is not void, but function has no return statement';
								$phpcsFile->addError($error, $return, 'InvalidNoReturn');
							}
							elseif ($voidReturnToken !== null) {
								$error = 'Function return type is not void, but function is returning void here';
								$phpcsFile->addError($error, $voidReturnToken, 'InvalidReturnNotVoid');
							}
						}
					}
				}
			}

			return;
		}

		if ($isSpecialMethod === true) {
			return;
		}

		// If there's any @inheritDoc inside, we don't need to check for missing comments.
		foreach ($tokens[ $commentStart ]['comment_tags'] as $tag) {
			if (strtolower($tokens[ $tag ]['content']) === '@inheritdoc') {
				return;
			}
		}

		$error = 'Missing @return tag in function comment';
		$phpcsFile->addError($error, $tokens[ $commentStart ]['comment_closer'], 'MissingReturn');
	}


	/**
	 * Process any throw tags that this function comment has.
	 *
	 * @param \PHP_CodeSniffer\Files\File $phpcsFile The file being scanned.
	 * @param int $stackPtr The position of the current token in the stack passed in $tokens.
	 * @param int $commentStart The position in the stack where the comment started.
	 * @return void
	 */
	protected function processThrows(File $phpcsFile, int $stackPtr, int $commentStart): void {
		$tokens = $phpcsFile->getTokens();

		if ($this->skipIfInheritdoc === true) {
			if ($this->checkInheritdoc($phpcsFile, $stackPtr, $commentStart) === true) {
				return;
			}
		}

		foreach ($tokens[ $commentStart ]['comment_tags'] as $pos => $tag) {
			if ($tokens[ $tag ]['content'] !== '@throws') {
				continue;
			}

			$exception = null;
			$comment = null;
			if ($tokens[ $tag + 2 ]['code'] === T_DOC_COMMENT_STRING) {
				$matches = [];
				preg_match('/([^\s]+)(?:\s+(.*))?/', $tokens[ $tag + 2 ]['content'], $matches);
				$exception = $matches[1];
				if (isset($matches[2]) === true && trim($matches[2]) !== '') {
					$comment = $matches[2];
				}
			}

			if ($exception === null) {
				$error = 'Exception type missing for @throws tag in function comment';
				$phpcsFile->addError($error, $tag, 'InvalidThrows');
			}
			elseif ($comment === null) {
				continue;
			}
			else {
				// Any strings until the next tag belong to this comment.
				if (isset($tokens[ $commentStart ]['comment_tags'][ $pos + 1 ]) === true) {
					$end = $tokens[ $commentStart ]['comment_tags'][ $pos + 1 ];
				}
				else {
					$end = $tokens[ $commentStart ]['comment_closer'];
				}

				for ($i = $tag + 3; $i < $end; $i++) {
					if ($tokens[ $i ]['code'] === T_DOC_COMMENT_STRING) {
						$comment .= ' ' . $tokens[ $i ]['content'];
					}
				}

				$comment = trim($comment);

				// Starts with a capital letter and ends with a fullstop.
				$firstChar = $comment[0];
				if (strtoupper($firstChar) !== $firstChar) {
					$error = '@throws tag comment must start with a capital letter';
					$phpcsFile->addError($error, $tag + 2, 'ThrowsNotCapital');
				}

				$lastChar = substr($comment, -1);
				if ($lastChar !== '.') {
					$error = '@throws tag comment must end with a full stop';
					$phpcsFile->addError($error, $tag + 2, 'ThrowsNoFullStop');
				}
			}
		}
	}


	/**
	 * Process the function parameter comments.
	 *
	 * @param \PHP_CodeSniffer\Files\File $phpcsFile The file being scanned.
	 * @param int $stackPtr The position of the current token in the stack passed in $tokens.
	 * @param int $commentStart The position in the stack where the comment started.
	 * @return void
	 */
	protected function processParams(File $phpcsFile, int $stackPtr, int $commentStart): void {
		if ($this->phpVersion === null) {
			$this->phpVersion = Config::getConfigData('php_version');
			if ($this->phpVersion === null) {
				$this->phpVersion = PHP_VERSION_ID;
			}
		}

		$tokens = $phpcsFile->getTokens();

		if ($this->skipIfInheritdoc === true) {
			if ($this->checkInheritdoc($phpcsFile, $stackPtr, $commentStart) === true) {
				return;
			}
		}

		$this->processTagOrder($phpcsFile, $stackPtr, $commentStart);

		$params = [];
		foreach ($tokens[ $commentStart ]['comment_tags'] as $pos => $tag) {
			if ($tokens[ $tag ]['content'] !== '@param') {
				continue;
			}

			$type = '';
			$typeSpace = 0;
			$var = '';
			$varSpace = 0;
			$comment = '';
			$commentLines = [];
			if ($tokens[ $tag + 2 ]['code'] === T_DOC_COMMENT_STRING) {
				$matches = [];
				preg_match(
					'/((?:(?![$.]|&(?=\$)).)*)(?:((?:\.\.\.)?(?:\$|&)[^\s]+)(?:(\s+)(.*))?)?/',
					$tokens[ $tag + 2 ]['content'],
					$matches
				);

				if (empty($matches) === false) {
					$typeLen = strlen($matches[1]);
					$type = trim($matches[1]);
					$typeSpace = $typeLen - strlen($type);
				}

				if ($tokens[ $tag + 2 ]['content'][0] === '$') {
					$error = 'Missing parameter type';
					$phpcsFile->addError($error, $tag, 'MissingParamType');
				}
				elseif (isset($matches[2]) === true) {
					$var = $matches[2];

					if (isset($matches[4]) === true) {
						$varSpace = strlen($matches[3]);
						$comment = $matches[4];
						$commentLines[] = [
							'comment' => $comment,
							'token' => $tag + 2,
							'indent' => $varSpace,
						];

						// Any strings until the next tag belong to this comment.
						if (isset($tokens[ $commentStart ]['comment_tags'][ $pos + 1 ]) === true) {
							$end = $tokens[ $commentStart ]['comment_tags'][ $pos + 1 ];
						}
						else {
							$end = $tokens[ $commentStart ]['comment_closer'];
						}

						for ($i = $tag + 3; $i < $end; $i++) {
							if ($tokens[ $i ]['code'] === T_DOC_COMMENT_STRING) {
								$indent = 0;
								if ($tokens[ $i - 1 ]['code'] === T_DOC_COMMENT_WHITESPACE) {
									$indent = $tokens[ $i - 1 ]['length'];
								}

								$comment .= ' ' . $tokens[ $i ]['content'];
								$commentLines[] = [
									'comment' => $tokens[ $i ]['content'],
									'token' => $i,
									'indent' => $indent,
								];
							}
						}
					}
				}
				else {
					$error = 'Missing parameter name';
					$phpcsFile->addError($error, $tag, 'MissingParamName');
				}
			}
			else {
				$error = 'Missing parameter type';
				$phpcsFile->addError($error, $tag, 'MissingParamType');
			}

			$params[] = [
				'tag' => $tag,
				'type' => $type,
				'var' => $var,
				'comment' => $comment,
				'commentLines' => $commentLines,
				'type_space' => $typeSpace,
				'var_space' => $varSpace,
			];
		}

		$realParams = $phpcsFile->getMethodParameters($stackPtr);
		$foundParams = [];

		// We want to use ... for all variable length arguments, so added
		// this prefix to the variable name so comparisons are easier.
		foreach ($realParams as $pos => $param) {
			if ($param['variable_length'] === true) {
				$realParams[ $pos ]['name'] = '...' . $realParams[ $pos ]['name'];
			}
		}

		foreach ($params as $pos => $param) {
			// If the type is empty, the whole line is empty.
			if ($param['type'] === '') {
				continue;
			}

			// Check the param type value.
			$typeNames = explode('|', $param['type']);
			$suggestedTypeNames = [];

			foreach ($typeNames as $typeName) {
				if ($typeName === '') {
					continue;
				}

				// Strip nullable operator.
				if ($typeName[0] === '?') {
					$typeName = substr($typeName, 1);
				}

				$suggestedName = static::suggestType($typeName);
				$suggestedTypeNames[] = $suggestedName;

				if (count($typeNames) > 1) {
					continue;
				}

				// Check type hint for array and custom type.
				$suggestedTypeHint = '';
				if (str_contains($suggestedName, 'array') || str_ends_with($suggestedName, '[]')) {
					$suggestedTypeHint = 'array';
				}
				elseif (str_contains($suggestedName, 'callable')) {
					$suggestedTypeHint = 'callable';
				}
				elseif (str_contains($suggestedName, 'callback')) {
					$suggestedTypeHint = 'callable';
				}
				elseif (isset(Common::ALLOWED_TYPES[ $suggestedName ]) === false) {
					// Generic arguments describe the PHPDoc type but are not part of the native type hint.
					$suggestedTypeHint = preg_replace('/<.*>$/', '', $suggestedName);
				}

				if ($this->phpVersion >= 70000) {
					if ($suggestedName === 'string') {
						$suggestedTypeHint = 'string';
					}
					elseif ($suggestedName === 'int' || $suggestedName === 'integer') {
						$suggestedTypeHint = 'int';
					}
					elseif ($suggestedName === 'float') {
						$suggestedTypeHint = 'float';
					}
					elseif ($suggestedName === 'bool' || $suggestedName === 'boolean') {
						$suggestedTypeHint = 'bool';
					}
				}

				if ($this->phpVersion >= 70200) {
					if ($suggestedName === 'object') {
						$suggestedTypeHint = 'object';
					}
				}

				if ($this->phpVersion >= 80000) {
					if ($suggestedName === 'mixed') {
						$suggestedTypeHint = 'mixed';
					}
				}

				if ($suggestedTypeHint !== '' && isset($realParams[ $pos ]) === true && $param['var'] !== '') {
					$typeHint = $realParams[ $pos ]['type_hint'];

					// Remove namespace prefixes when comparing.
					$compareTypeHint = substr($suggestedTypeHint, (strlen($typeHint) * -1));
					$resolvedSuggestedTypeHint = $this->resolveTypeHint($phpcsFile, $stackPtr, $suggestedTypeHint);
					$resolvedTypeHint = $this->resolveTypeHint($phpcsFile, $stackPtr, $typeHint);
					$typeHintsMatch = strtolower(ltrim($resolvedSuggestedTypeHint, '\\')) === strtolower(ltrim($resolvedTypeHint, '\\'));

					if ($typeHint === '') {
						$error = 'Type hint "%s" missing for %s';
						$data = [
							$suggestedTypeHint,
							$param['var'],
						];

						$errorCode = 'TypeHintMissing';
						if (
							$suggestedTypeHint === 'string'
							|| $suggestedTypeHint === 'int'
							|| $suggestedTypeHint === 'float'
							|| $suggestedTypeHint === 'bool'
						) {
							$errorCode = 'Scalar' . $errorCode;
						}

						$phpcsFile->addError($error, $stackPtr, $errorCode, $data);
					}
					elseif (
						$typeHintsMatch === false
						&& $typeHint !== $compareTypeHint
						&& $typeHint !== '?' . $compareTypeHint
					) {
						$error = 'Expected type hint "%s"; found "%s" for %s';
						$data = [
							$suggestedTypeHint,
							$typeHint,
							$param['var'],
						];
						$phpcsFile->addError($error, $stackPtr, 'IncorrectTypeHint', $data);
					}
				}
				elseif ($suggestedTypeHint === '' && isset($realParams[ $pos ]) === true) {
					$typeHint = $realParams[ $pos ]['type_hint'];
					if ($typeHint !== '') {
						$error = 'Unknown type hint "%s" found for %s';
						$data = [
							$typeHint,
							$param['var'],
						];
						$phpcsFile->addError($error, $stackPtr, 'InvalidTypeHint', $data);
					}
				}
			}

			$suggestedType = implode('|', $suggestedTypeNames);
			if ($param['type'] !== $suggestedType) {
				$error = 'Expected "%s" but found "%s" for parameter type';
				$data = [
					$suggestedType,
					$param['type'],
				];

				$fix = $phpcsFile->addFixableError($error, $param['tag'], 'IncorrectParamVarName', $data);
				if ($fix === true) {
					$phpcsFile->fixer->beginChangeset();

					$content = $suggestedType;
					$content .= str_repeat(' ', $param['type_space']);
					$content .= $param['var'];
					$content .= str_repeat(' ', $param['var_space']);
					if (isset($param['commentLines'][0]) === true) {
						$content .= $param['commentLines'][0]['comment'];
					}

					$phpcsFile->fixer->replaceToken($param['tag'] + 2, $content);

					// Fix up the indent of additional comment lines.
					foreach ($param['commentLines'] as $lineNum => $line) {
						if (
							$lineNum === 0
							|| $param['commentLines'][ $lineNum ]['indent'] === 0
						) {
							continue;
						}

						$diff = strlen($param['type']) - strlen($suggestedType);
						$newIndent = $param['commentLines'][ $lineNum ]['indent'] - $diff;
						$phpcsFile->fixer->replaceToken(
							$param['commentLines'][ $lineNum ]['token'] - 1,
							str_repeat(' ', $newIndent)
						);
					}

					$phpcsFile->fixer->endChangeset();
				}
			}

			if ($param['var'] === '') {
				continue;
			}

			$foundParams[] = $param['var'];

			// Check number of spaces after the type.
			$this->checkSpacingAfterParamType($phpcsFile, $param);

			// Make sure the param name is correct.
			if (isset($realParams[ $pos ]) === true) {
				$realName = $realParams[ $pos ]['name'];
				$paramVarName = $param['var'];

				if ($param['var'][0] === '&') {
					// Even when passed by reference, the variable name in $realParams does not have
					// a leading '&'. This sniff will accept both '&$var' and '$var' in these cases.
					$paramVarName = substr($param['var'], 1);

					// This makes sure that the 'MissingParamTag' check won't throw a false positive.
					$foundParams[ count($foundParams) - 1 ] = $paramVarName;

					if ($realParams[ $pos ]['pass_by_reference'] !== true && $realName === $paramVarName) {
						// Don't complain about this unless the param name is otherwise correct.
						$error = 'Doc comment for parameter %s is prefixed with "&" but parameter is not passed by reference';
						$code = 'ParamNameUnexpectedAmpersandPrefix';
						$data = [$paramVarName];

						// We're not offering an auto-fix here because we can't tell if the docblock
						// is wrong, or the parameter should be passed by reference.
						$phpcsFile->addError($error, $param['tag'], $code, $data);
					}
				}

				if ($realName !== $paramVarName) {
					$code = 'ParamNameNoMatch';
					$data = [
						$paramVarName,
						$realName,
					];

					$error = 'Doc comment for parameter %s does not match ';
					if (strtolower($paramVarName) === strtolower($realName)) {
						$error .= 'case of ';
						$code = 'ParamNameNoCaseMatch';
					}

					$error .= 'actual variable name %s';

					$phpcsFile->addError($error, $param['tag'], $code, $data);
				}
			}
			elseif (!str_ends_with($param['var'], ',...')) {
				// We must have an extra parameter comment.
				$error = 'Superfluous parameter comment';
				$phpcsFile->addError($error, $param['tag'], 'ExtraParamComment');
			}

			if ($param['comment'] === '') {
				continue;
			}

			// Check number of spaces after the var name.
			$this->checkSpacingAfterParamName($phpcsFile, $param);

			// Param comments must start with a capital letter
			if (preg_match('/^(\p{Ll}|\P{L})/u', $param['comment']) === 1) {
				$error = 'Parameter comment must start with a capital letter';
				$phpcsFile->addError($error, $param['tag'], 'ParamCommentNotCapital');
			}
		}

		$realNames = [];
		foreach ($realParams as $realParam) {
			$realNames[] = $realParam['name'];
		}

		// If there's any @inheritDoc inside, we don't need to check for missing comments.
		foreach ($tokens[ $commentStart ]['comment_tags'] as $tag) {
			if (strtolower($tokens[ $tag ]['content']) === '@inheritdoc') {
				return;
			}
		}

		// Report missing comments.
		$diff = array_diff($realNames, $foundParams);
		foreach ($diff as $neededParam) {
			$error = 'Doc comment for parameter "%s" missing';
			$data = [$neededParam];
			$phpcsFile->addError($error, $commentStart, 'MissingParamTag', $data);
		}
	}


	/**
	 * Check that `@param`, `@return` and `@throws` tags appear in the expected relative order.
	 *
	 * All `@param` tags must come before the @return tag, which in turn must come before any @throws
	 * tags. Other tags (e.g. `@param` tag against the real parameter at the same position and reports a name mismatch when the
	 * order is wrong.
	 *
	 * @param \PHP_CodeSniffer\Files\File $phpcsFile The file being scanned.
	 * @param int $stackPtr The position of the current token in the stack passed in $tokens.
	 * @param int $commentStart The position in the stack where the comment started.
	 * @return void
	 */
	protected function processTagOrder(File $phpcsFile, int $stackPtr, int $commentStart): void {
		$tokens = $phpcsFile->getTokens();

		$order = [
			'@param' => 0,
			'@return' => 1,
			'@throws' => 2,
			'@see' => 3,
			'@todo' => 4,
			'@noinspection' => 5,
		];

		$lastRank = -1;
		$lastTag = null;

		foreach ($tokens[ $commentStart ]['comment_tags'] as $tag) {
			$tagName = $tokens[ $tag ]['content'];
			if (isset($order[ $tagName ]) === false) {
				continue;
			}

			$rank = $order[ $tagName ];
			if ($rank < $lastRank) {
				$error = '%s tag must appear before %s in a function comment';
				$data = [
					$tagName,
					$lastTag,
				];
				$phpcsFile->addError($error, $tag, 'TagOrder', $data);
			}
			else {
				$lastRank = $rank;
				$lastTag = $tagName;
			}
		}
	}


	/**
	 * Check the spacing after the type of parameter.
	 *
	 * Param lists are intentionally not column-aligned across the tags of a single docblock, so the
	 * expected spacing is always the fixed amount given in $spacing, regardless of the length of the
	 * type or of any other `@param` tag in the same comment.
	 *
	 * @param \PHP_CodeSniffer\Files\File $phpcsFile The file being scanned.
	 * @param array $param The parameter to be checked.
	 * @param int $spacing The number of spaces required after the type.
	 * @return void
	 */
	protected function checkSpacingAfterParamType(File $phpcsFile, array $param, int $spacing = 1): void {
		// Check number of spaces after the type.
		if ($param['type_space'] !== $spacing) {
			$error = 'Expected %s space(s) after parameter type; %s found';
			$data = [
				$spacing,
				$param['type_space'],
			];

			$fix = $phpcsFile->addFixableError($error, $param['tag'], 'SpacingAfterParamType', $data);
			if ($fix === true) {
				$phpcsFile->fixer->beginChangeset();

				$content = $param['type'];
				$content .= str_repeat(' ', $spacing);
				$content .= $param['var'];
				$content .= str_repeat(' ', $param['var_space']);
				$content .= $param['commentLines'][0]['comment'];
				$phpcsFile->fixer->replaceToken($param['tag'] + 2, $content);

				// Fix up the indent of additional comment lines.
				$diff = $param['type_space'] - $spacing;
				foreach ($param['commentLines'] as $lineNum => $line) {
					if (
						$lineNum === 0
						|| $param['commentLines'][ $lineNum ]['indent'] === 0
					) {
						continue;
					}

					$newIndent = $param['commentLines'][ $lineNum ]['indent'] - $diff;
					if ($newIndent <= 0) {
						continue;
					}

					$phpcsFile->fixer->replaceToken(
						$param['commentLines'][ $lineNum ]['token'] - 1,
						str_repeat(' ', $newIndent)
					);
				}

				$phpcsFile->fixer->endChangeset();
			}
		}
	}


	/**
	 * Check the spacing after the name of a parameter.
	 *
	 * Like the type spacing above, this is intentionally not column-aligned across the `@param` tags of
	 * a single docblock; the expected spacing is always the fixed amount given in $spacing.
	 *
	 * @param \PHP_CodeSniffer\Files\File $phpcsFile The file being scanned.
	 * @param array $param The parameter to be checked.
	 * @param int $spacing The number of spaces required after the parameter name.
	 * @return void
	 */
	protected function checkSpacingAfterParamName(File $phpcsFile, array $param, int $spacing = 1): void {
		// Check number of spaces after the var name.
		if ($param['var_space'] !== $spacing) {
			$error = 'Expected %s space(s) after parameter name; %s found';
			$data = [
				$spacing,
				$param['var_space'],
			];

			$fix = $phpcsFile->addFixableError($error, $param['tag'], 'SpacingAfterParamName', $data);
			if ($fix === true) {
				$phpcsFile->fixer->beginChangeset();

				$content = $param['type'];
				$content .= str_repeat(' ', $param['type_space']);
				$content .= $param['var'];
				$content .= str_repeat(' ', $spacing);
				$content .= $param['commentLines'][0]['comment'];
				$phpcsFile->fixer->replaceToken($param['tag'] + 2, $content);

				// Fix up the indent of additional comment lines.
				foreach ($param['commentLines'] as $lineNum => $line) {
					if (
						$lineNum === 0
						|| $param['commentLines'][ $lineNum ]['indent'] === 0
					) {
						continue;
					}

					$diff = $param['var_space'] - $spacing;
					$newIndent = $param['commentLines'][ $lineNum ]['indent'] - $diff;
					if ($newIndent <= 0) {
						continue;
					}

					$phpcsFile->fixer->replaceToken(
						$param['commentLines'][ $lineNum ]['token'] - 1,
						str_repeat(' ', $newIndent)
					);
				}

				$phpcsFile->fixer->endChangeset();
			}
		}
	}


	/**
	 * Determines whether the whole comment is an inheritdoc comment.
	 *
	 * @param \PHP_CodeSniffer\Files\File $phpcsFile The file being scanned.
	 * @param int $stackPtr The position of the current token the stack passed in $tokens.
	 * @param int $commentStart The position in the stack where the comment started.
	 * @return bool TRUE if the docblock contains only `@inheritdoc` (case-insensitive).
	 */
	protected function checkInheritdoc(File $phpcsFile, int $stackPtr, int $commentStart): bool {
		$tokens = $phpcsFile->getTokens();

		$allowedTokens = [
			T_DOC_COMMENT_OPEN_TAG,
			T_DOC_COMMENT_WHITESPACE,
			T_DOC_COMMENT_STAR,
		];
		for ($i = $commentStart; $i <= $tokens[ $commentStart ]['comment_closer']; $i++) {
			if (in_array($tokens[ $i ]['code'], $allowedTokens, true) === false) {
				return str_contains(strtolower(trim($tokens[ $i ]['content'])), '@inheritdoc');
			}
		}

		return false;
	}


	/**
	 * Resolves a native type hint or PHPDoc type through imported class aliases.
	 *
	 * @param \PHP_CodeSniffer\Files\File $phpcsFile The file being scanned.
	 * @param int $stackPtr The position of the current token in the stack passed in $tokens.
	 * @param string $typeHint The type hint to resolve.
	 * @return string
	 */
	protected function resolveTypeHint(File $phpcsFile, int $stackPtr, string $typeHint): string {
		$normalizedTypeHint = ltrim($typeHint, '?\\');
		$aliases = $this->getTypeAliases($phpcsFile, $stackPtr);

		return $aliases[ $normalizedTypeHint ] ?? $normalizedTypeHint;
	}


	/**
	 * Gets class aliases imported before a declaration.
	 *
	 * @param \PHP_CodeSniffer\Files\File $phpcsFile The file being scanned.
	 * @param int $stackPtr The position of the current token in the stack passed in $tokens.
	 * @return array<string, string>
	 */
	protected function getTypeAliases(File $phpcsFile, int $stackPtr): array {
		$tokens = $phpcsFile->getTokens();
		$aliases = [];

		for ($tokenPtr = 0; $tokenPtr < $stackPtr; $tokenPtr++) {
			if ($tokens[ $tokenPtr ]['code'] !== T_USE) {
				continue;
			}

			$statement = '';
			for ($statementPtr = $tokenPtr + 1; $statementPtr < $stackPtr; $statementPtr++) {
				if ($tokens[ $statementPtr ]['code'] === T_SEMICOLON) {
					break;
				}

				$statement .= $tokens[ $statementPtr ]['content'];
			}

			foreach (preg_split('/\s*,\s*/', trim($statement)) ?: [] as $import) {
				if (preg_match('/^(?:function|const)\s/i', $import) === 1) {
					continue;
				}

				if (preg_match('/^\\\\?([a-z_][a-z0-9_\\\\]*)(?:\s+as\s+([a-z_][a-z0-9_]*))?$/i', $import, $matches) !== 1) {
					continue;
				}

				$importedType = ltrim($matches[1], '\\');
				$alias = $matches[2] ?? substr($importedType, strrpos($importedType, '\\') + 1);
				$aliases[ $alias ] = $importedType;
			}

			$tokenPtr = $statementPtr;
		}

		return $aliases;
	}


	/**
	 * @param string $varType
	 * @return string
	 */
	protected static function suggestType(string $varType): string {
		return match ($varType) {
			'bool', 'boolean' => 'bool',
			'int', 'integer' => 'int',
			default => Common::suggestType($varType),
		};
	}
}
