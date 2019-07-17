<?php
/**
 * Update functions
 *
 * Incremental updates
 *
 * Update() function called if ROSARIO_VERSION != version in DB
 *
 * @package RosarioSIS
 * @subpackage ProgramFunctions
 */

/**
 * Update manager function
 *
 * Call the specific versions functions
 *
 * @since 2.9
 *
 * @return boolean false if wrong version or update failed, else true
 */
function Update()
{
	$from_version = Config( 'VERSION' );

	$to_version = ROSARIO_VERSION;

	/**
	 * Check if Update() version < ROSARIO_VERSION.
	 *
	 * Prevent DB version update if new Update.fnc.php file has NOT been uploaded YET.
	 * Update must be run once both new Warehouse.php & Update.fnc.php files are uploaded.
	 */
	if ( version_compare( '4.9.1', ROSARIO_VERSION, '<' ) )
	{
		return false;
	}

	// Check if version in DB >= ROSARIO_VERSION.
	if ( version_compare( $from_version, $to_version, '>=' ) )
	{
		return false;
	}

	require_once 'ProgramFunctions/UpdateV2_3.fnc.php';

	$return = true;

	switch ( true )
	{
		case version_compare( $from_version, '2.9-alpha', '<' ) :

			$return = _update29alpha();

		case version_compare( $from_version, '2.9.2', '<' ) :

			$return = _update292();

		case version_compare( $from_version, '2.9.5', '<' ) :

			$return = _update295();

		case version_compare( $from_version, '2.9.12', '<' ) :

			$return = _update2912();

		case version_compare( $from_version, '2.9.13', '<' ) :

			$return = _update2913();

		case version_compare( $from_version, '2.9.14', '<' ) :

			$return = _update2914();

		case version_compare( $from_version, '3.0', '<' ) :

			$return = _update30();

		case version_compare( $from_version, '3.1', '<' ) :

			$return = _update31();

		case version_compare( $from_version, '3.5', '<' ) :

			$return = _update35();

		case version_compare( $from_version, '3.7-beta', '<' ) :

			$return = _update37beta();

		case version_compare( $from_version, '3.9', '<' ) :

			$return = _update39();

		case version_compare( $from_version, '4.0-beta', '<' ) :

			$return = _update40beta();

		case version_compare( $from_version, '4.2-beta', '<' ) :

			$return = _update42beta();

		case version_compare( $from_version, '4.3-beta', '<' ) :

			$return = _update43beta();

		case version_compare( $from_version, '4.4-beta', '<' ) :

			$return = _update44beta();

		case version_compare( $from_version, '4.4-beta2', '<' ) :

			$return = _update44beta2();

		case version_compare( $from_version, '4.5-beta2', '<' ) :

			$return = _update45beta2();

		case version_compare( $from_version, '4.6-beta', '<' ) :

			$return = _update46beta();

		case version_compare( $from_version, '4.7-beta', '<' ) :

			$return = _update47beta();

		case version_compare( $from_version, '4.7-beta2', '<' ) :

			$return = _update47beta2();

		case version_compare( $from_version, '4.9-beta', '<' ) :

			$return = _update49beta();
	}

	// Update version in DB CONFIG table.
	Config( 'VERSION', ROSARIO_VERSION );

	return $return;
}


/**
 * Is function called by Update()?
 *
 * Local function
 *
 * @example _isCallerUpdate( debug_backtrace() );
 *
 * @since 2.9.13
 *
 * @param  array   $callers debug_backtrace().
 *
 * @return boolean          Exit with error message if not called by Update().
 */
function _isCallerUpdate( $callers )
{
	if ( ! isset( $callers[1]['function'] )
		|| $callers[1]['function'] !== 'Update' )
	{
		exit( 'Error: the update functions must be called by Update() only!' );
	}

	return true;
}


/**
 * Update to version 4.0
 *
 * 0. Create plpgsql language in case it does not exist.
 * 1. Fix SQL error in calc_gpa_mp function on INSERT Final Grades for students with various enrollment records.
 * enroll_grade view was returning various rows
 * while primary key contraints to a unique (student_id,marking_period_id) pair
 *
 * Local function
 *
 * @since 4.0
 *
 * @return boolean false if update failed or if not called by Update(), else true
 */
function _update40beta()
{
	_isCallerUpdate( debug_backtrace() );

	$return = true;

	// 0. Create plpgsql language in case it does not exist.
	// 1. Fix SQL error in calc_gpa_mp function on INSERT Final Grades for students with various enrollment records.
	DBQuery( "CREATE FUNCTION create_language_plpgsql()
	RETURNS BOOLEAN AS $$
		CREATE LANGUAGE plpgsql;
		SELECT TRUE;
	$$ LANGUAGE SQL;

	SELECT CASE WHEN NOT
		(
			SELECT  TRUE AS exists
			FROM    pg_language
			WHERE   lanname = 'plpgsql'
			UNION
			SELECT  FALSE AS exists
			ORDER BY exists DESC
			LIMIT 1
		)
	THEN
		create_language_plpgsql()
	ELSE
		FALSE
	END AS plpgsql_created;

	DROP FUNCTION create_language_plpgsql();

	CREATE OR REPLACE FUNCTION calc_gpa_mp(integer, character varying) RETURNS integer AS $$
	DECLARE
		s_id ALIAS for $1;
		mp_id ALIAS for $2;
		oldrec student_mp_stats%ROWTYPE;
	BEGIN
	  SELECT * INTO oldrec FROM student_mp_stats WHERE student_id = s_id and cast(marking_period_id as text) = mp_id;

	  IF FOUND THEN
		UPDATE STUDENT_MP_STATS SET
			sum_weighted_factors = rcg.sum_weighted_factors,
			sum_unweighted_factors = rcg.sum_unweighted_factors,
			cr_weighted_factors = rcg.cr_weighted,
			cr_unweighted_factors = rcg.cr_unweighted,
			gp_credits = rcg.gp_credits,
			cr_credits = rcg.cr_credits

		FROM (
		select
			sum(weighted_gp*credit_attempted/gp_scale) as sum_weighted_factors,
			sum(unweighted_gp*credit_attempted/gp_scale) as sum_unweighted_factors,
			sum(credit_attempted) as gp_credits,
			sum( case when class_rank = 'Y' THEN weighted_gp*credit_attempted/gp_scale END ) as cr_weighted,
			sum( case when class_rank = 'Y' THEN unweighted_gp*credit_attempted/gp_scale END ) as cr_unweighted,
			sum( case when class_rank = 'Y' THEN credit_attempted END) as cr_credits

			from student_report_card_grades where student_id = s_id
			and cast(marking_period_id as text) = mp_id
			 and not gp_scale = 0 group by student_id, marking_period_id
			) as rcg
	WHERE student_id = s_id and cast(marking_period_id as text) = mp_id;
		RETURN 1;
	ELSE
		INSERT INTO STUDENT_MP_STATS (student_id, marking_period_id, sum_weighted_factors, sum_unweighted_factors, grade_level_short, cr_weighted_factors, cr_unweighted_factors, gp_credits, cr_credits)

			select
				srcg.student_id, (srcg.marking_period_id::text)::int,
				sum(weighted_gp*credit_attempted/gp_scale) as sum_weighted_factors,
				sum(unweighted_gp*credit_attempted/gp_scale) as sum_unweighted_factors,
				(select eg.short_name
					from enroll_grade eg, marking_periods mp
					where eg.student_id = s_id
					and eg.syear = mp.syear
					and eg.school_id = mp.school_id
					and eg.start_date <= mp.end_date
					and cast(mp.marking_period_id as text) = mp_id
					order by eg.start_date desc
					limit 1),
				sum( case when class_rank = 'Y' THEN weighted_gp*credit_attempted/gp_scale END ) as cr_weighted,
				sum( case when class_rank = 'Y' THEN unweighted_gp*credit_attempted/gp_scale END ) as cr_unweighted,
				sum(credit_attempted) as gp_credits,
				sum(case when class_rank = 'Y' THEN credit_attempted END) as cr_credits
			from student_report_card_grades srcg
			where srcg.student_id = s_id and cast(srcg.marking_period_id as text) = mp_id and not srcg.gp_scale = 0
			group by srcg.student_id, srcg.marking_period_id, short_name;
		END IF;
		RETURN 0;
	END
	$$
		LANGUAGE plpgsql;" );

	return $return;
}


/**
 * Update to version 4.2
 *
 * 1. CONFIG table:
 * Change config_value column type to text
 * Was character varying(2550) which could prevent saving rich text with base64 images
 * in case there is an issue with the image upload.
 *
 * Local function
 *
 * @since 4.2
 *
 * @return boolean false if update failed or if not called by Update(), else true
 */
function _update42beta()
{
	_isCallerUpdate( debug_backtrace() );

	$return = true;

	/**
	 * 1. CONFIG table:
	 * Change config_value column type to text
	 * Was character varying(2550) which could prevent saving rich text with base64 images
	 * in case there is an issue with the image upload.
	 */
	DBQuery( "ALTER TABLE config
		ALTER COLUMN config_value TYPE text;" );

	return $return;
}


/**
 * Update to version 4.3
 *
 * 1. COURSES table: Add DESCRIPTION column.
 *
 * Local function
 *
 * @since 4.3
 *
 * @return boolean false if update failed or if not called by Update(), else true
 */
function _update43beta()
{
	_isCallerUpdate( debug_backtrace() );

	$return = true;

	/**
	 * 1. COURSES table: Add DESCRIPTION column.
	 */
	$description_column_exists = DBGet( "SELECT 1 FROM pg_attribute
		WHERE attrelid = (SELECT oid FROM pg_class WHERE relname = 'courses')
		AND attname = 'description';" );

	if ( ! $description_column_exists )
	{
		DBQuery( "ALTER TABLE ONLY courses
			ADD COLUMN description text;" );
	}

	return $return;
}


/**
 * Update to version 4.4
 *
 * 1. GRADEBOOK_ASSIGNMENTS table: Add FILE column.
 * 2. GRADEBOOK_ASSIGNMENTS table: Change DESCRIPTION column type to text.
 * 3. GRADEBOOK_ASSIGNMENTS table: Convert DESCRIPTION values from MarkDown to HTML.
 *
 * Local function
 *
 * @since 4.4
 *
 * @return boolean false if update failed or if not called by Update(), else true
 */
function _update44beta()
{
	_isCallerUpdate( debug_backtrace() );

	require_once 'ProgramFunctions/MarkDownHTML.fnc.php';

	$return = true;

	/**
	 * 1. GRADEBOOK_ASSIGNMENTS table:
	 * Add FILE column
	 */
	$file_column_exists = DBGet( "SELECT 1 FROM pg_attribute
		WHERE attrelid = (SELECT oid FROM pg_class WHERE relname = 'gradebook_assignments')
		AND attname = 'file';" );

	if ( ! $file_column_exists )
	{
		DBQuery( "ALTER TABLE ONLY gradebook_assignments
			ADD COLUMN file character varying(1000);" );
	}

	/**
	 * 2. GRADEBOOK_ASSIGNMENTS table:
	 * Change DESCRIPTION column type to text
	 * Was character varying(1000) which could prevent saving rich text with base64 images
	 */
	DBQuery( "ALTER TABLE gradebook_assignments
		ALTER COLUMN description TYPE text;" );

	/**
	 * 3. GRADEBOOK_ASSIGNMENTS table:
	 * Convert DESCRIPTION values from MarkDown to HTML.
	 */
	$assignments_RET = DBGet( "SELECT assignment_id,description
		FROM gradebook_assignments
		WHERE description IS NOT NULL;" );

	$assignment_update_sql = "UPDATE GRADEBOOK_ASSIGNMENTS
		SET DESCRIPTION='%s'
		WHERE ASSIGNMENT_ID='%d';";

	$assignments_update_sql = '';

	foreach ( (array) $assignments_RET as $assignment )
	{
		$description_html = MarkDownToHTML( $assignment['DESCRIPTION'] );

		$assignments_update_sql .= sprintf(
			$assignment_update_sql,
			DBEscapeString( $description_html ),
			$assignment['ASSIGNMENT_ID']
		);
	}

	if ( $assignments_update_sql )
	{
		DBQuery( $assignments_update_sql );
	}

	return $return;
}


/**
 * Update to version 4.4
 *
 * 1. Add PASSWORD_STRENGTH to CONFIG table.
 *
 * Local function
 *
 * @since 4.4
 *
 * @return boolean false if update failed or if not called by Update(), else true
 */
function _update44beta2()
{
	_isCallerUpdate( debug_backtrace() );

	$return = true;

	/**
	 * 1. Add PASSWORD_STRENGTH to CONFIG table.
	 */
	$password_strength_added = DBGet( "SELECT 1 FROM CONFIG WHERE TITLE='PASSWORD_STRENGTH'" );

	if ( ! $password_strength_added )
	{
		DBQuery( "INSERT INTO config VALUES (0, 'PASSWORD_STRENGTH', '1');" );
	}

	return $return;
}


/**
 * Update to version 4.5
 *
 * 1. GRADEBOOK_ASSIGNMENT_TYPES table: Add CREATED_MP column.
 *
 * Local function
 *
 * @since 4.5
 *
 * @return boolean false if update failed or if not called by Update(), else true
 */
function _update45beta2()
{
	_isCallerUpdate( debug_backtrace() );

	$return = true;

	/**
	 * 1. GRADEBOOK_ASSIGNMENT_TYPES table: Add CREATED_MP column.
	 */
	$created_at_column_exists = DBGet( "SELECT 1 FROM pg_attribute
		WHERE attrelid = (SELECT oid FROM pg_class WHERE relname = 'gradebook_assignment_types')
		AND attname = 'created_mp';" );

	if ( ! $created_at_column_exists )
	{
		DBQuery( "ALTER TABLE ONLY gradebook_assignment_types
			ADD COLUMN created_mp integer;" );
	}

	return $return;
}


/**
 * Update to version 4.6
 *
 * 1. ELIGIBILITY_ACTIVITIES table: Add COMMENT column.
 *
 * Local function
 *
 * @since 4.6
 *
 * @return boolean false if update failed or if not called by Update(), else true
 */
function _update46beta()
{
	_isCallerUpdate( debug_backtrace() );

	$return = true;

	/**
	 * 1. ELIGIBILITY_ACTIVITIES table: Add COMMENT column.
	 */
	$comment_column_exists = DBGet( "SELECT 1 FROM pg_attribute
		WHERE attrelid = (SELECT oid FROM pg_class WHERE relname = 'eligibility_activities')
		AND attname = 'comment';" );

	if ( ! $comment_column_exists )
	{
		DBQuery( "ALTER TABLE ONLY eligibility_activities
			ADD COLUMN comment text;" );
	}

	return $return;
}


/**
 * Update to version 4.7
 *
 * 1. Convert "Edit Pull-Down" fields to "Auto Pull-Down":
 * ADDRESS_FIELDS, CUSTOM_FIELDS, PEOPLE_FIELDS, SCHOOL_FIELDS & STAFF_FIELDS tables
 *
 * 2. Convert "Coded Pull-Down" fields to "Export Pull-Down":
 * ADDRESS_FIELDS, CUSTOM_FIELDS, PEOPLE_FIELDS, SCHOOL_FIELDS & STAFF_FIELDS tables
 *
 * 3. Change Pull-Down (Auto & Export), Select Multiple from Options, Text, Long Text columns type to text:
 * ADDRESS, STUDENTS, PEOPLE, SCHOOLS & STAFF tables
 *
 * Local function
 *
 * @since 4.7
 *
 * @return boolean false if update failed or if not called by Update(), else true
 */
function _update47beta()
{
	_isCallerUpdate( debug_backtrace() );

	$return = true;

	/**
	 * 1. Convert "Edit Pull-Down" fields to "Auto Pull-Down":
	 * ADDRESS_FIELDS, CUSTOM_FIELDS, PEOPLE_FIELDS, SCHOOL_FIELDS & STAFF_FIELDS tables
	 */
	$sql_convert_fields = "UPDATE ADDRESS_FIELDS SET TYPE='autos' WHERE TYPE='edits';";
	$sql_convert_fields .= "UPDATE CUSTOM_FIELDS SET TYPE='autos' WHERE TYPE='edits';";
	$sql_convert_fields .= "UPDATE PEOPLE_FIELDS SET TYPE='autos' WHERE TYPE='edits';";
	$sql_convert_fields .= "UPDATE SCHOOL_FIELDS SET TYPE='autos' WHERE TYPE='edits';";
	$sql_convert_fields .= "UPDATE STAFF_FIELDS SET TYPE='autos' WHERE TYPE='edits';";

	DBQuery( $sql_convert_fields );


	/**
	 * 2. Convert "Coded Pull-Down" fields to "Export Pull-Down":
	 * ADDRESS_FIELDS, CUSTOM_FIELDS, PEOPLE_FIELDS, SCHOOL_FIELDS & STAFF_FIELDS tables
	 */
	$sql_convert_fields = "UPDATE ADDRESS_FIELDS SET TYPE='codeds' WHERE TYPE='exports';";
	$sql_convert_fields .= "UPDATE CUSTOM_FIELDS SET TYPE='codeds' WHERE TYPE='exports';";
	$sql_convert_fields .= "UPDATE PEOPLE_FIELDS SET TYPE='codeds' WHERE TYPE='exports';";
	$sql_convert_fields .= "UPDATE SCHOOL_FIELDS SET TYPE='codeds' WHERE TYPE='exports';";
	$sql_convert_fields .= "UPDATE STAFF_FIELDS SET TYPE='codeds' WHERE TYPE='exports';";

	DBQuery( $sql_convert_fields );

	$sql_fields_column_type = '';


	/**
	 * 3. Change Pull-Down (Auto & Export), Select Multiple from Options, Text, Long Text columns type to text:
	 * ADDRESS, STUDENTS, PEOPLE, SCHOOLS & STAFF tables
	 */
	$types = "'select','autos','exports','multiple','text','textarea'";

	$fields_column_RET = DBGet( "SELECT ID FROM ADDRESS_FIELDS WHERE TYPE IN(" . $types . ")" );

	foreach ( (array) $fields_column_RET as $field_column )
	{
		$sql_fields_column_type .= "ALTER TABLE ADDRESS
			ALTER COLUMN " . DBEscapeIdentifier( 'CUSTOM_' . $field_column['ID'] ) . " TYPE text;";
	}

	$fields_column_RET = DBGet( "SELECT ID FROM CUSTOM_FIELDS WHERE TYPE IN(" . $types . ")" );

	foreach ( (array) $fields_column_RET as $field_column )
	{
		$sql_fields_column_type .= "ALTER TABLE STUDENTS
			ALTER COLUMN " . DBEscapeIdentifier( 'CUSTOM_' . $field_column['ID'] ) . " TYPE text;";
	}

	$fields_column_RET = DBGet( "SELECT ID FROM PEOPLE_FIELDS WHERE TYPE IN(" . $types . ")" );

	foreach ( (array) $fields_column_RET as $field_column )
	{
		$sql_fields_column_type .= "ALTER TABLE PEOPLE
			ALTER COLUMN " . DBEscapeIdentifier( 'CUSTOM_' . $field_column['ID'] ) . " TYPE text;";
	}

	$fields_column_RET = DBGet( "SELECT ID FROM SCHOOL_FIELDS WHERE TYPE IN(" . $types . ")" );

	foreach ( (array) $fields_column_RET as $field_column )
	{
		$sql_fields_column_type .= "ALTER TABLE SCHOOLS
			ALTER COLUMN " . DBEscapeIdentifier( 'CUSTOM_' . $field_column['ID'] ) . " TYPE text;";
	}

	$fields_column_RET = DBGet( "SELECT ID FROM STAFF_FIELDS WHERE TYPE IN(" . $types . ")" );

	foreach ( (array) $fields_column_RET as $field_column )
	{
		$sql_fields_column_type .= "ALTER TABLE STAFF
			ALTER COLUMN " . DBEscapeIdentifier( 'CUSTOM_' . $field_column['ID'] ) . " TYPE text;";
	}

	if ( $sql_fields_column_type )
	{
		DBQuery( $sql_fields_column_type );
	}

	return $return;
}


/**
 * Update to version 4.7
 *
 * 1. Add CLASS_RANK_CALCULATE_MPS to CONFIG table.
 * 2. SQL performance: rewrite set_class_rank_mp() function.
 * 3. SQL move calc_cum_gpa_mp() function into t_update_mp_stats() trigger.
 *
 * Local function
 *
 * @since 4.7
 *
 * @return boolean false if update failed or if not called by Update(), else true
 */
function _update47beta2()
{
	_isCallerUpdate( debug_backtrace() );

	$return = true;

	/**
	 * 1. Add CLASS_RANK_CALCULATE_MPS to CONFIG table.
	 */
	$class_rank_added = DBGet( "SELECT 1 FROM CONFIG WHERE TITLE='CLASS_RANK_CALCULATE_MPS'" );

	if ( ! $class_rank_added )
	{
		$schools_RET = DBGet( "SELECT ID FROM SCHOOLS;" );

		foreach ( (array) $schools_RET as $school )
		{
			$mps_RET = DBGet( "SELECT MARKING_PERIOD_ID
				FROM MARKING_PERIODS
				WHERE SCHOOL_ID='" . $school['ID'] . "'", array(), array( 'MARKING_PERIOD_ID' ) );

			$mps = array_keys( $mps_RET );

			$class_rank_mps = '|' . implode( '||', $mps ) . '|';

			DBQuery( "INSERT INTO config
				VALUES('" . $school['ID'] . "','CLASS_RANK_CALCULATE_MPS','" . $class_rank_mps . "');" );
		}
	}

	/**
	 * 2. SQL performance: rewrite set_class_rank_mp() function.
	 * Create plpgsql language first if does not exist.
	 */
	DBQuery( "CREATE FUNCTION create_language_plpgsql()
	RETURNS BOOLEAN AS $$
		CREATE LANGUAGE plpgsql;
		SELECT TRUE;
	$$ LANGUAGE SQL;

	SELECT CASE WHEN NOT
		(
			SELECT  TRUE AS exists
			FROM    pg_language
			WHERE   lanname = 'plpgsql'
			UNION
			SELECT  FALSE AS exists
			ORDER BY exists DESC
			LIMIT 1
		)
	THEN
		create_language_plpgsql()
	ELSE
		FALSE
	END AS plpgsql_created;

	DROP FUNCTION create_language_plpgsql();

	CREATE OR REPLACE FUNCTION set_class_rank_mp(character varying) RETURNS integer
		AS $$
	DECLARE
		mp_id alias for $1;
	BEGIN
	update student_mp_stats
	set cum_rank = rank.rank, class_size = rank.class_size
	from (select mp.marking_period_id, sgm.student_id,
		(select count(*)+1
			from student_mp_stats sgm3
			where sgm3.cum_cr_weighted_factor > sgm.cum_cr_weighted_factor
			and sgm3.marking_period_id = mp.marking_period_id
			and sgm3.student_id in (select distinct sgm2.student_id
				from student_mp_stats sgm2, student_enrollment se2
				where sgm2.student_id = se2.student_id
				and sgm2.marking_period_id = mp.marking_period_id
				and se2.grade_id = se.grade_id)) as rank,
		(select count(*)
			from student_mp_stats sgm4
			where sgm4.marking_period_id = mp.marking_period_id
			and sgm4.student_id in (select distinct sgm5.student_id
				from student_mp_stats sgm5, student_enrollment se3
				where sgm5.student_id = se3.student_id
				and sgm5.marking_period_id = mp.marking_period_id
				and se3.grade_id = se.grade_id)) as class_size
		from student_enrollment se, student_mp_stats sgm, marking_periods mp
		where se.student_id = sgm.student_id
		and sgm.marking_period_id = mp.marking_period_id
		and cast(mp.marking_period_id as text) = mp_id
		and se.syear = mp.syear
		and not sgm.cum_cr_weighted_factor is null) as rank
	where student_mp_stats.marking_period_id = rank.marking_period_id
	and student_mp_stats.student_id = rank.student_id;
	RETURN 1;
	END;
	$$
		LANGUAGE plpgsql;" );


	/**
	 * 3. SQL move calc_cum_gpa_mp() function into t_update_mp_stats() trigger.
	 * Create plpgsql language first if does not exist.
	 */
	DBQuery( "CREATE FUNCTION create_language_plpgsql()
	RETURNS BOOLEAN AS $$
		CREATE LANGUAGE plpgsql;
		SELECT TRUE;
	$$ LANGUAGE SQL;

	SELECT CASE WHEN NOT
		(
			SELECT  TRUE AS exists
			FROM    pg_language
			WHERE   lanname = 'plpgsql'
			UNION
			SELECT  FALSE AS exists
			ORDER BY exists DESC
			LIMIT 1
		)
	THEN
		create_language_plpgsql()
	ELSE
		FALSE
	END AS plpgsql_created;

	DROP FUNCTION create_language_plpgsql();

	CREATE OR REPLACE FUNCTION t_update_mp_stats() RETURNS  \"trigger\"
	    AS $$
	begin

	  IF tg_op = 'DELETE' THEN
	    PERFORM calc_gpa_mp(OLD.student_id::int, OLD.marking_period_id::varchar);
	    PERFORM calc_cum_gpa(OLD.marking_period_id::varchar, OLD.student_id::int);
	    PERFORM calc_cum_cr_gpa(OLD.marking_period_id::varchar, OLD.student_id::int);

	  ELSE
	    --IF tg_op = 'INSERT' THEN
	        --we need to do stuff here to gather other information since it's a new record.
	    --ELSE
	        --if report_card_grade_id changes, then we need to reset gp values
	    --  IF NOT NEW.report_card_grade_id = OLD.report_card_grade_id THEN
	            --
	    PERFORM calc_gpa_mp(NEW.student_id::int, NEW.marking_period_id::varchar);
	    PERFORM calc_cum_gpa(NEW.marking_period_id::varchar, NEW.student_id::int);
	    PERFORM calc_cum_cr_gpa(NEW.marking_period_id::varchar, NEW.student_id::int);
	  END IF;
	  return NULL;
	end
	$$
	    LANGUAGE plpgsql;" );

	return $return;
}


/**
 * Update to version 4.9
 *
 * 1. PROGRAM_CONFIG table: Add Allow Teachers to edit gradebook grades for past quarters option.
 *
 * Local function
 *
 * @since 4.9
 *
 * @return boolean false if update failed or if not called by Update(), else true
 */
function _update49beta()
{
	_isCallerUpdate( debug_backtrace() );

	$return = true;

	/**
	 * 1. PROGRAM_CONFIG table: Add Allow Teachers to edit gradebook grades for past quarters option.
	 */
	$config_option_exists = DBGet( "SELECT 1 FROM PROGRAM_CONFIG
		WHERE TITLE='GRADES_GRADEBOOK_TEACHER_ALLOW_EDIT';" );

	if ( ! $config_option_exists )
	{
		DBQuery( "INSERT INTO PROGRAM_CONFIG (VALUE,PROGRAM,TITLE,SCHOOL_ID,SYEAR)
			SELECT 'Y','grades','GRADES_GRADEBOOK_TEACHER_ALLOW_EDIT',ID,SYEAR
			FROM SCHOOLS;" );
	}

	return $return;
}


/**
 * Update to version 5.0
 *
 * 1. Rename sequences.
 * Use default name generated by serial: "[table]_[serial_column]_seq".
 * 2. Rename sequences for add-on modules.
 * Use default name generated by serial: "[table]_[serial_column]_seq".
 * 3. Add foreign keys.
 * student_id, staff_id, school_id, marking_period_id, course_period_id, course_id.
 *
 * Local function
 *
 * @since 5.0
 *
 * @return boolean false if update failed or if not called by Update(), else true
 */
function _update50beta()
{
	_isCallerUpdate( debug_backtrace() );

	$return = true;

	$rename_sequence = function( $old_sequence, $new_sequence )
	{
		$sequence_exists = DBGetOne( "SELECT 1 FROM pg_class
			WHERE relname='" . DBEscapeString( $new_sequence ) . "';" );

		if ( ! $sequence_exists )
		{
			DBQuery( "ALTER SEQUENCE " . $old_sequence . " RENAME TO " . $new_sequence . ";" );
		}
	};

	/**
	 * 1. Rename sequences.
	 * Use default name generated by serial: "[table]_[serial_column]_seq".
	 */
	$rename_sequence( 'user_profiles_seq', 'user_profiles_id_seq' );
	$rename_sequence( 'students_join_people_seq', 'students_join_people_id_seq' );
	$rename_sequence( 'students_join_address_seq', 'students_join_address_id_seq' );
	$rename_sequence( 'students_seq', 'students_student_id_seq' );
	$rename_sequence( 'student_report_card_grades_seq', 'student_report_card_grades_id_seq' );
	$rename_sequence( 'student_medical_visits_seq', 'student_medical_visits_id_seq' );
	$rename_sequence( 'student_medical_alerts_seq', 'student_medical_alerts_id_seq' );
	$rename_sequence( 'student_medical_seq', 'student_medical_id_seq' );
	$rename_sequence( 'student_field_categories_seq', 'student_field_categories_id_seq' );
	$rename_sequence( 'student_enrollment_codes_seq', 'student_enrollment_codes_id_seq' );
	$rename_sequence( 'student_enrollment_seq', 'student_enrollment_id_seq' );
	$rename_sequence( 'staff_fields_seq', 'staff_fields_id_seq' );
	$rename_sequence( 'staff_field_categories_seq', 'staff_field_categories_id_seq' );
	$rename_sequence( 'staff_seq', 'staff_staff_id_seq' );
	$rename_sequence( 'school_periods_seq', 'school_periods_period_id_seq' );
	$rename_sequence( 'schools_seq', 'schools_id_seq' );
	$rename_sequence( 'school_gradelevels_seq', 'school_gradelevels_id_seq' );
	$rename_sequence( 'school_fields_seq', 'school_fields_id_seq' );
	$rename_sequence( 'schedule_requests_seq', 'schedule_requests_request_id_seq' );
	$rename_sequence( 'resources_seq', 'resources_id_seq' );
	$rename_sequence( 'report_card_grades_seq', 'report_card_grades_id_seq' );
	$rename_sequence( 'report_card_grade_scales_seq', 'report_card_grade_scales_id_seq' );
	$rename_sequence( 'report_card_comments_seq', 'report_card_comments_id_seq' );
	$rename_sequence( 'report_card_comment_codes_seq', 'report_card_comment_codes_id_seq' );
	$rename_sequence( 'report_card_comment_code_scales_seq', 'report_card_comment_code_scales_id_seq' );
	$rename_sequence( 'report_card_comment_categories_seq', 'report_card_comment_categories_id_seq' );
	$rename_sequence( 'portal_polls_seq', 'portal_polls_id_seq' );
	$rename_sequence( 'portal_poll_questions_seq', 'portal_poll_questions_id_seq' );
	$rename_sequence( 'portal_notes_seq', 'portal_notes_id_seq' );
	$rename_sequence( 'people_join_contacts_seq', 'people_join_contacts_id_seq' );
	$rename_sequence( 'people_fields_seq', 'people_fields_id_seq' );
	$rename_sequence( 'people_field_categories_seq', 'people_field_categories_id_seq' );
	$rename_sequence( 'people_seq', 'people_person_id_seq' );
	$rename_sequence( 'marking_period_seq', 'school_marking_periods_marking_period_id_seq' );
	$rename_sequence( 'gradebook_assignments_seq', 'gradebook_assignments_assignment_id_seq' );
	$rename_sequence( 'gradebook_assignment_types_seq', 'gradebook_assignment_types_assignment_type_id_seq' );
	$rename_sequence( 'food_service_transactions_seq', 'food_service_transactions_transaction_id_seq' );
	$rename_sequence( 'food_service_staff_transactions_seq', 'food_service_staff_transactions_transaction_id_seq' );
	$rename_sequence( 'food_service_menus_seq', 'food_service_menus_menu_id_seq' );
	$rename_sequence( 'food_service_menu_items_seq', 'food_service_menu_items_menu_item_id_seq' );
	$rename_sequence( 'food_service_items_seq', 'food_service_items_item_id_seq' );
	$rename_sequence( 'food_service_categories_seq', 'food_service_categories_category_id_seq' );
	$rename_sequence( 'eligibility_activities_seq', 'eligibility_activities_id_seq' );
	$rename_sequence( 'discipline_referrals_seq', 'discipline_referrals_id_seq' );
	$rename_sequence( 'discipline_fields_seq', 'discipline_fields_id_seq' );
	$rename_sequence( 'discipline_field_usage_seq', 'discipline_field_usage_id_seq' );
	$rename_sequence( 'custom_seq', 'custom_fields_id_seq' );
	$rename_sequence( 'course_subjects_seq', 'course_subjects_subject_id_seq' );
	$rename_sequence( 'course_period_school_periods_seq', 'course_period_school_periods_course_period_school_periods_id_seq' );
	$rename_sequence( 'courses_seq', 'courses_course_id_seq' );
	$rename_sequence( 'course_periods_seq', 'course_periods_course_period_id_seq' );
	$rename_sequence( 'calendar_events_seq', 'calendar_events_id_seq' );
	$rename_sequence( 'billing_payments_seq', 'billing_payments_id_seq' );
	$rename_sequence( 'billing_fees_seq', 'billing_fees_id_seq' );
	$rename_sequence( 'attendance_codes_seq', 'attendance_codes_id_seq' );
	$rename_sequence( 'attendance_code_categories_seq', 'attendance_code_categories_id_seq' );
	$rename_sequence( 'calendars_seq', 'attendance_calendars_calendar_id_seq' );
	$rename_sequence( 'address_fields_seq', 'address_fields_id_seq' );
	$rename_sequence( 'address_field_categories_seq', 'address_field_categories_id_seq' );
	$rename_sequence( 'address_seq', 'address_address_id_seq' );
	$rename_sequence( 'accounting_payments_seq', 'accounting_payments_id_seq' );
	$rename_sequence( 'accounting_salaries_seq', 'accounting_salaries_id_seq' );
	$rename_sequence( 'accounting_incomes_seq', 'accounting_incomes_id_seq' );

	/**
	 * 2. Rename sequences for add-on modules.
	 * Use default name generated by serial: "[table]_[serial_column]_seq".
	 */
	$rename_sequence( 'billing_fees_monthly_seq', 'billing_fees_monthly_id_seq' );
	$rename_sequence( 'school_inventory_categories_seq', 'school_inventory_categories_category_id_seq' );
	$rename_sequence( 'school_inventory_items_seq', 'school_inventory_items_item_id_seq' );
	$rename_sequence( 'saved_reports_seq', 'saved_reports_id_seq' );
	$rename_sequence( 'saved_calculations_seq', 'saved_calculations_id_seq' );
	$rename_sequence( 'messages_seq', 'messages_message_id_seq' );

	$add_foreign_key = function( $table, $column, $reference )
	{
		$fk_name = $table . '_' . $column . '_fk';

		$fk_exists = DBGetOne( "SELECT 1 FROM information_schema.table_constraints
			WHERE constraint_type='FOREIGN KEY'
			AND constraint_name='" . DBEscapeString( $fk_name ) . "';" );

		if ( ! $fk_exists )
		{
			DBQuery( "ALTER TABLE " . DBEscapeIdentifier( $table ) . " ADD CONSTRAINT " . $fk_name .
				" FOREIGN KEY (" . $column . ") REFERENCES " . $reference . ";" );
		}
	};

	/**
	 * 3. Add foreign keys.
	 * student_id
	 */
	$add_foreign_key( 'students_join_users', 'student_id', 'students(student_id)' );
	$add_foreign_key( 'students_join_people', 'student_id', 'students(student_id)' );
	$add_foreign_key( 'students_join_address', 'student_id', 'students(student_id)' );
	$add_foreign_key( 'student_enrollment', 'student_id', 'students(student_id)' );
	$add_foreign_key( 'student_report_card_grades', 'student_id', 'students(student_id)' );
	$add_foreign_key( 'student_report_card_comments', 'student_id', 'students(student_id)' );
	$add_foreign_key( 'student_mp_stats', 'student_id', 'students(student_id)' );
	$add_foreign_key( 'student_mp_comments', 'student_id', 'students(student_id)' );
	$add_foreign_key( 'student_medical_visits', 'student_id', 'students(student_id)' );
	$add_foreign_key( 'student_medical_alerts', 'student_id', 'students(student_id)' );
	$add_foreign_key( 'student_medical', 'student_id', 'students(student_id)' );
	$add_foreign_key( 'student_eligibility_activities', 'student_id', 'students(student_id)' );
	$add_foreign_key( 'student_assignments', 'student_id', 'students(student_id)' );
	$add_foreign_key( 'schedule_requests', 'student_id', 'students(student_id)' );
	$add_foreign_key( 'schedule', 'student_id', 'students(student_id)' );
	$add_foreign_key( 'lunch_period', 'student_id', 'students(student_id)' );
	$add_foreign_key( 'gradebook_grades', 'student_id', 'students(student_id)' );
	$add_foreign_key( 'food_service_transactions', 'student_id', 'students(student_id)' );
	$add_foreign_key( 'food_service_student_accounts', 'student_id', 'students(student_id)' );
	$add_foreign_key( 'eligibility', 'student_id', 'students(student_id)' );
	$add_foreign_key( 'discipline_referrals', 'student_id', 'students(student_id)' );
	$add_foreign_key( 'billing_payments', 'student_id', 'students(student_id)' );
	$add_foreign_key( 'billing_fees', 'student_id', 'students(student_id)' );
	$add_foreign_key( 'attendance_period', 'student_id', 'students(student_id)' );
	$add_foreign_key( 'attendance_day', 'student_id', 'students(student_id)' );

	/**
	 * 3. Add foreign keys.
	 * staff_id
	 */
	$add_foreign_key( 'course_periods', 'teacher_id', 'staff(staff_id)' );
	$add_foreign_key( 'templates', 'staff_id', 'staff(staff_id)' );
	$add_foreign_key( 'students_join_users', 'staff_id', 'staff(staff_id)' );
	$add_foreign_key( 'staff_exceptions', 'user_id', 'staff(staff_id)' );
	$add_foreign_key( 'grades_completed', 'staff_id', 'staff(staff_id)' );
	$add_foreign_key( 'gradebook_assignments', 'staff_id', 'staff(staff_id)' );
	$add_foreign_key( 'gradebook_assignment_types', 'staff_id', 'staff(staff_id)' );
	$add_foreign_key( 'food_service_staff_transactions', 'staff_id', 'staff(staff_id)' );
	$add_foreign_key( 'food_service_staff_accounts', 'staff_id', 'staff(staff_id)' );
	$add_foreign_key( 'eligibility_completed', 'staff_id', 'staff(staff_id)' );
	$add_foreign_key( 'discipline_referrals', 'staff_id', 'staff(staff_id)' );
	$add_foreign_key( 'attendance_completed', 'staff_id', 'staff(staff_id)' );
	$add_foreign_key( 'accounting_payments', 'staff_id', 'staff(staff_id)' );
	$add_foreign_key( 'accounting_salaries', 'staff_id', 'staff(staff_id)' );

	/**
	 * 3. Add foreign keys.
	 * school_id
	 */
	$add_foreign_key( 'student_enrollment', 'school_id', 'schools(id)' );
	$add_foreign_key( 'student_report_card_grades', 'school_id', 'schools(id)' );
	$add_foreign_key( 'student_report_card_comments', 'school_id', 'schools(id)' );
	$add_foreign_key( 'school_periods', 'school_id', 'schools(id)' );
	$add_foreign_key( 'school_gradelevels', 'school_id', 'schools(id)' );
	$add_foreign_key( 'schedule_requests', 'school_id', 'schools(id)' );
	$add_foreign_key( 'schedule', 'school_id', 'schools(id)' );
	$add_foreign_key( 'resources', 'school_id', 'schools(id)' );
	$add_foreign_key( 'report_card_grades', 'school_id', 'schools(id)' );
	$add_foreign_key( 'report_card_grade_scales', 'school_id', 'schools(id)' );
	$add_foreign_key( 'report_card_comments', 'school_id', 'schools(id)' );
	$add_foreign_key( 'report_card_comment_codes', 'school_id', 'schools(id)' );
	$add_foreign_key( 'report_card_comment_code_scales', 'school_id', 'schools(id)' );
	$add_foreign_key( 'report_card_comment_categories', 'school_id', 'schools(id)' );
	$add_foreign_key( 'program_config', 'school_id', 'schools(id)' );
	$add_foreign_key( 'portal_polls', 'school_id', 'schools(id)' );
	$add_foreign_key( 'portal_notes', 'school_id', 'schools(id)' );
	$add_foreign_key( 'history_marking_periods', 'school_id', 'schools(id)' );
	$add_foreign_key( 'school_marking_periods', 'school_id', 'schools(id)' );
	$add_foreign_key( 'food_service_transactions', 'school_id', 'schools(id)' );
	$add_foreign_key( 'food_service_staff_transactions', 'school_id', 'schools(id)' );
	$add_foreign_key( 'food_service_menus', 'school_id', 'schools(id)' );
	$add_foreign_key( 'food_service_menu_items', 'school_id', 'schools(id)' );
	$add_foreign_key( 'food_service_items', 'school_id', 'schools(id)' );
	$add_foreign_key( 'food_service_categories', 'school_id', 'schools(id)' );
	$add_foreign_key( 'eligibility_activities', 'school_id', 'schools(id)' );
	$add_foreign_key( 'discipline_referrals', 'school_id', 'schools(id)' );
	$add_foreign_key( 'discipline_field_usage', 'school_id', 'schools(id)' );
	$add_foreign_key( 'course_subjects', 'school_id', 'schools(id)' );
	$add_foreign_key( 'courses', 'school_id', 'schools(id)' );
	$add_foreign_key( 'course_periods', 'school_id', 'schools(id)' );
	$add_foreign_key( 'calendar_events', 'school_id', 'schools(id)' );
	$add_foreign_key( 'billing_payments', 'school_id', 'schools(id)' );
	$add_foreign_key( 'billing_fees', 'school_id', 'schools(id)' );
	$add_foreign_key( 'attendance_codes', 'school_id', 'schools(id)' );
	$add_foreign_key( 'attendance_code_categories', 'school_id', 'schools(id)' );
	$add_foreign_key( 'attendance_calendars', 'school_id', 'schools(id)' );
	$add_foreign_key( 'attendance_calendar', 'school_id', 'schools(id)' );
	$add_foreign_key( 'accounting_payments', 'school_id', 'schools(id)' );
	$add_foreign_key( 'accounting_salaries', 'school_id', 'schools(id)' );
	$add_foreign_key( 'accounting_incomes', 'school_id', 'schools(id)' );

	/**
	 * 3. Add foreign keys.
	 * marking_period_id
	 */
	$add_foreign_key( 'student_report_card_comments', 'marking_period_id', 'school_marking_periods(marking_period_id)' );
	$add_foreign_key( 'student_mp_comments', 'marking_period_id', 'school_marking_periods(marking_period_id)' );
	$add_foreign_key( 'schedule_requests', 'marking_period_id', 'school_marking_periods(marking_period_id)' );
	$add_foreign_key( 'schedule', 'marking_period_id', 'school_marking_periods(marking_period_id)' );
	$add_foreign_key( 'lunch_period', 'marking_period_id', 'school_marking_periods(marking_period_id)' );
	$add_foreign_key( 'grades_completed', 'marking_period_id', 'school_marking_periods(marking_period_id)' );
	$add_foreign_key( 'gradebook_assignments', 'marking_period_id', 'school_marking_periods(marking_period_id)' );
	$add_foreign_key( 'course_periods', 'marking_period_id', 'school_marking_periods(marking_period_id)' );
	$add_foreign_key( 'attendance_period', 'marking_period_id', 'school_marking_periods(marking_period_id)' );
	$add_foreign_key( 'attendance_day', 'marking_period_id', 'school_marking_periods(marking_period_id)' );

	/**
	 * 3. Add foreign keys.
	 * course_period_id
	 */
	$add_foreign_key( 'student_report_card_grades', 'course_period_id', 'course_periods(course_period_id)' );
	$add_foreign_key( 'student_report_card_comments', 'course_period_id', 'course_periods(course_period_id)' );
	$add_foreign_key( 'schedule', 'course_period_id', 'course_periods(course_period_id)' );
	$add_foreign_key( 'lunch_period', 'course_period_id', 'course_periods(course_period_id)' );
	$add_foreign_key( 'grades_completed', 'course_period_id', 'course_periods(course_period_id)' );
	$add_foreign_key( 'gradebook_grades', 'course_period_id', 'course_periods(course_period_id)' );
	$add_foreign_key( 'gradebook_assignments', 'course_period_id', 'course_periods(course_period_id)' );
	$add_foreign_key( 'eligibility', 'course_period_id', 'course_periods(course_period_id)' );
	$add_foreign_key( 'course_period_school_periods', 'course_period_id', 'course_periods(course_period_id)' );
	$add_foreign_key( 'attendance_period', 'course_period_id', 'course_periods(course_period_id)' );

	/**
	 * 3. Add foreign keys.
	 * course_id
	 */
	$add_foreign_key( 'schedule_requests', 'course_id', 'courses(course_id)' );
	$add_foreign_key( 'schedule', 'course_id', 'courses(course_id)' );
	$add_foreign_key( 'report_card_comments', 'course_id', 'courses(course_id)' );
	$add_foreign_key( 'report_card_comment_categories', 'course_id', 'courses(course_id)' );
	$add_foreign_key( 'gradebook_assignments', 'course_id', 'courses(course_id)' );
	$add_foreign_key( 'gradebook_assignment_types', 'course_id', 'courses(course_id)' );
	$add_foreign_key( 'course_periods', 'course_id', 'courses(course_id)' );

	return $return;
}
