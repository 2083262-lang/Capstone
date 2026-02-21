<?php 
// Define the limit for displaying agents in each section on this overview page
$display_limit = 8;

// --- Fetch Agents Needing Profile Completion ---
// These are agents who have an account but haven't filled out their agent_information yet,
// or their profile_completed status is 0.
$sql_needs_profile = "SELECT a.account_id, a.first_name, a.middle_name, a.last_name, a.email, a.phone_number,
                             ai.profile_picture_url, ai.license_number, ai.specialization, ai.years_experience, ai.bio
                      FROM accounts a
                      JOIN user_roles ur ON a.role_id = ur.role_id
                      LEFT JOIN agent_information ai ON a.account_id = ai.account_id
                      WHERE ur.role_name = 'agent' AND (ai.profile_completed IS NULL OR ai.profile_completed = 0)
                      ORDER BY a.date_registered ASC
                      LIMIT ?";
$stmt_needs_profile = $conn->prepare($sql_needs_profile);
$stmt_needs_profile->bind_param("i", $display_limit);
$stmt_needs_profile->execute();
$result_needs_profile = $stmt_needs_profile->get_result();
$agents_needs_profile = $result_needs_profile->fetch_all(MYSQLI_ASSOC);
$stmt_needs_profile->close();

// Count total agents needing profile for 'View More' button
$sql_count_needs_profile = "SELECT COUNT(a.account_id)
                            FROM accounts a
                            JOIN user_roles ur ON a.role_id = ur.role_id
                            LEFT JOIN agent_information ai ON a.account_id = ai.account_id
                            WHERE ur.role_name = 'agent' AND (ai.profile_completed IS NULL OR ai.profile_completed = 0)";
$result_count_needs_profile = $conn->query($sql_count_needs_profile);
$total_needs_profile = $result_count_needs_profile->fetch_row()[0];


// --- Fetch Agents Pending Admin Approval ---
$sql_pending_approval = "SELECT a.account_id, a.first_name, a.middle_name, a.last_name, a.email, a.phone_number,
                                ai.profile_picture_url, ai.license_number, ai.specialization, ai.years_experience, ai.bio, ai.is_approved
                         FROM accounts a
                         JOIN user_roles ur ON a.role_id = ur.role_id
                         JOIN agent_information ai ON a.account_id = ai.account_id
                         WHERE ur.role_name = 'agent' AND ai.profile_completed = 1 AND ai.is_approved = 0
                         ORDER BY a.date_registered ASC
                         LIMIT ?";
$stmt_pending_approval = $conn->prepare($sql_pending_approval);
$stmt_pending_approval->bind_param("i", $display_limit);
$stmt_pending_approval->execute();
$result_pending_approval = $stmt_pending_approval->get_result();
$agents_pending_approval = $result_pending_approval->fetch_all(MYSQLI_ASSOC);
$stmt_pending_approval->close();

// Count total agents pending approval for 'View More' button
$sql_count_pending_approval = "SELECT COUNT(a.account_id)
                               FROM accounts a
                               JOIN user_roles ur ON a.role_id = ur.role_id
                               JOIN agent_information ai ON a.account_id = ai.account_id
                               WHERE ur.role_name = 'agent' AND ai.profile_completed = 1 AND ai.is_approved = 0";
$result_count_pending_approval = $conn->query($sql_count_pending_approval);
$total_pending_approval = $result_count_pending_approval->fetch_row()[0];


// --- Fetch Approved Agents ---
$sql_approved = "SELECT a.account_id, a.first_name, a.middle_name, a.last_name, a.email, a.phone_number,
                        ai.profile_picture_url, ai.license_number, ai.specialization, ai.years_experience, ai.bio, ai.is_approved
                 FROM accounts a
                 JOIN user_roles ur ON a.role_id = ur.role_id
                 JOIN agent_information ai ON a.account_id = ai.account_id
                 WHERE ur.role_name = 'agent' AND ai.profile_completed = 1 AND ai.is_approved = 1
                 ORDER BY a.first_name ASC, a.last_name ASC
                 LIMIT ?";
$stmt_approved = $conn->prepare($sql_approved);
$stmt_approved->bind_param("i", $display_limit);
$stmt_approved->execute();
$result_approved = $stmt_approved->get_result();
$agents_approved = $result_approved->fetch_all(MYSQLI_ASSOC);
$stmt_approved->close();

// Count total approved agents for 'View More' button
$sql_count_approved = "SELECT COUNT(a.account_id)
                       FROM accounts a
                       JOIN user_roles ur ON a.role_id = ur.role_id
                       JOIN agent_information ai ON a.account_id = ai.account_id
                       WHERE ur.role_name = 'agent' AND ai.profile_completed = 1 AND ai.is_approved = 1";
$result_count_approved = $conn->query($sql_count_approved);
$total_approved = $result_count_approved->fetch_row()[0];


// Close the database connection
$conn->close();
?>