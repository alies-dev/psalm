<?php

declare(strict_types=1);

namespace Psalm\Tests\Internal;

use Psalm\Internal\Clause;

use function array_intersect_key;
use function array_keys;
use function array_pop;
use function array_values;
use function count;
use function in_array;
use function reset;

/**
 * Verbatim copy of Algebra::simplifyCNF before it was indexed, used as the oracle of
 * AlgebraEquivalenceTest. A semantic change to Algebra::simplifyCNF must be made here too.
 */
final class ReferenceAlgebra
{
    /**
     * This is a very simple simplification heuristic
     * for CNF formulae.
     *
     * It simplifies formulae:
     *     ($a) && ($a || $b) => $a
     *     (!$a) && (!$b) && ($a || $b || $c) => $c
     *
     * @param list<Clause>  $clauses
     * @return list<Clause>
     * @psalm-capabilities write-refs
     */
    public static function simplifyCNF(array $clauses): array
    {
        $clause_count = count($clauses);

        //65536 seems to be a significant threshold, when put at 65537, the code https://psalm.dev/r/216f362ea6 goes
        //from seconds in analysis to many minutes
        if ($clause_count > 65_536) {
            return [];
        }

        if ($clause_count > 50) {
            $all_has_unknown = true;

            foreach ($clauses as $clause) {
                $clause_has_unknown = false;
                foreach ($clause->possibilities as $key => $_) {
                    if ($key[0] === '*') {
                        $clause_has_unknown = true;
                        break;
                    }
                }

                if (!$clause_has_unknown) {
                    $all_has_unknown = false;
                    break;
                }
            }

            if ($all_has_unknown) {
                return $clauses;
            }
        }

        $cloned_clauses = [];

        // avoid strict duplicates
        foreach ($clauses as $clause) {
            $cloned_clauses[$clause->hash] = $clause;
        }

        // remove impossible types
        foreach ($cloned_clauses as $clause_a_hash => $clause_a) {
            if (!$clause_a->reconcilable || $clause_a->wedge) {
                continue;
            }
            $clause_a_keys = array_keys($clause_a->possibilities);

            if (count($clause_a->possibilities) !== 1 || count(array_values($clause_a->possibilities)[0]) !== 1) {
                foreach ($cloned_clauses as $clause_b) {
                    if ($clause_a === $clause_b || !$clause_b->reconcilable || $clause_b->wedge) {
                        continue;
                    }

                    if ($clause_a_keys === array_keys($clause_b->possibilities)) {
                        $opposing_keys = [];

                        foreach ($clause_a->possibilities as $key => $a_possibilities) {
                            $b_possibilities = $clause_b->possibilities[$key];

                            if (array_keys($clause_a->possibilities[$key])
                                === array_keys($clause_b->possibilities[$key])
                            ) {
                                continue;
                            }

                            if (count($a_possibilities) === 1 && count($b_possibilities) === 1) {
                                if (reset($a_possibilities)->isNegationOf(reset($b_possibilities))) {
                                    $opposing_keys[] = $key;
                                    continue;
                                }
                            }

                            continue 2;
                        }

                        if (count($opposing_keys) === 1) {
                            unset($cloned_clauses[$clause_a_hash]);

                            $clause_a = $clause_a->removePossibilities($opposing_keys[0]);

                            if (!$clause_a) {
                                continue 2;
                            }

                            $cloned_clauses[$clause_a->hash] = $clause_a;
                        }
                    }
                }

                continue;
            }

            $clause_var = array_keys($clause_a->possibilities)[0];
            $only_type = array_pop(array_values($clause_a->possibilities)[0]);
            $negated_clause_type = $only_type->getNegation();
            $negated_clause_type_string = (string)$negated_clause_type;

            foreach ($cloned_clauses as $clause_b_hash => $clause_b) {
                if ($clause_a === $clause_b || !$clause_b->reconcilable || $clause_b->wedge) {
                    continue;
                }

                if (isset($clause_b->possibilities[$clause_var])) {
                    $clause_var_possibilities = $clause_b->possibilities[$clause_var];

                    if (isset($clause_var_possibilities[$negated_clause_type_string])) {
                        unset($clause_var_possibilities[$negated_clause_type_string]);

                        unset($cloned_clauses[$clause_b_hash]);

                        if (!$clause_var_possibilities) {
                            $updated_clause = $clause_b->removePossibilities($clause_var);

                            if ($updated_clause) {
                                $cloned_clauses[$updated_clause->hash] = $updated_clause;
                            }
                        } else {
                            $updated_clause = $clause_b->addPossibilities(
                                $clause_var,
                                $clause_var_possibilities,
                            );

                            $cloned_clauses[$updated_clause->hash] = $updated_clause;
                        }
                    }
                }
            }
        }

        $simplified_clauses = [];

        foreach ($cloned_clauses as $clause_a) {
            $is_redundant = false;

            foreach ($cloned_clauses as $clause_b) {
                if ($clause_a === $clause_b
                    || !$clause_b->reconcilable
                    || $clause_b->wedge
                    || $clause_a->wedge
                ) {
                    continue;
                }

                if ($clause_a->contains($clause_b)) {
                    $is_redundant = true;
                    break;
                }
            }

            if (!$is_redundant) {
                $simplified_clauses[$clause_a->hash] = $clause_a;
            }
        }

        $clause_count = count($simplified_clauses);

        // simplify (A || X) && (!A || Y) && (X || Y)
        // to
        // simplify (A || X) && (!A || Y)
        // where X and Y are sets of orred terms
        if ($clause_count > 2 && $clause_count < 256) {
            $clauses = array_values($simplified_clauses);
            for ($i = 0; $i < $clause_count; $i++) {
                $clause_a = $clauses[$i];
                for ($k = $i + 1; $k < $clause_count; $k++) {
                    $clause_b = $clauses[$k];
                    $common_keys = array_keys(
                        array_intersect_key($clause_a->possibilities, $clause_b->possibilities),
                    );
                    if ($common_keys) {
                        $common_negated_keys = [];
                        foreach ($common_keys as $common_key) {
                            if (count($clause_a->possibilities[$common_key]) === 1
                                && count($clause_b->possibilities[$common_key]) === 1
                                && reset($clause_a->possibilities[$common_key])->isNegationOf(
                                    reset($clause_b->possibilities[$common_key]),
                                )
                            ) {
                                $common_negated_keys[] = $common_key;
                            }
                        }

                        if ($common_negated_keys) {
                            $new_possibilities = [];

                            foreach ($clause_a->possibilities as $var_id => $possibilities) {
                                if (in_array($var_id, $common_negated_keys, true)) {
                                    continue;
                                }

                                if (!isset($new_possibilities[$var_id])) {
                                    $new_possibilities[$var_id] = $possibilities;
                                } else {
                                    $new_possibilities[$var_id] += $possibilities;
                                }
                            }

                            foreach ($clause_b->possibilities as $var_id => $possibilities) {
                                if (in_array($var_id, $common_negated_keys, true)) {
                                    continue;
                                }

                                if (!isset($new_possibilities[$var_id])) {
                                    $new_possibilities[$var_id] = $possibilities;
                                } else {
                                    $new_possibilities[$var_id] += $possibilities;
                                }
                            }

                            $conflict_clause = (new Clause(
                                $new_possibilities,
                                $clause_a->creating_conditional_id,
                                $clause_a->creating_conditional_id,
                                false,
                                true,
                                true,
                                [],
                            ));

                            unset($simplified_clauses[$conflict_clause->hash]);
                        }
                    }
                }
            }
        }

        return array_values($simplified_clauses);
    }
}
